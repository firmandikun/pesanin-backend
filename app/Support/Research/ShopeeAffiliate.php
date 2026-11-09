<?php

namespace App\Support\Research;

use App\Models\UserIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopee Affiliate Open API (Indonesia), the official way to read marketplace-wide product offers:
 * products by keyword with price, units sold, rating, shop and commission.
 * Credentials (AppId + Secret) come from affiliate.shopee.co.id → Open API once the account is approved.
 */
class ShopeeAffiliate
{
    public const ENDPOINT = 'https://open-api.affiliate.shopee.co.id/graphql';

    /** Pesanin sort → productOfferV2 sortType. */
    public const SORT = ['sales' => 2, 'relevance' => 1, 'price_asc' => 4, 'price_desc' => 3, 'commission' => 5];

    public function __construct(private UserIntegration $integration) {}

    /**
     * The seller's own account when connected, otherwise Pesanin's shared account from .env.
     * @return array{0: ?self, 1: 'own'|'pesanin'|null}
     */
    public static function resolve(\App\Models\User $user): array
    {
        if ($own = self::for($user->integration('shopee_affiliate'))) {
            return [$own, 'own'];
        }
        $shared = new UserIntegration(['provider' => 'shopee_affiliate', 'credentials' => ['app_id' => config('services.shopee_affiliate.app_id'), 'secret' => config('services.shopee_affiliate.secret')], 'status' => 'active']);

        return ($c = self::for($shared)) ? [$c, 'pesanin'] : [null, null];
    }

    public static function for(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'shopee_affiliate' && $i->key('app_id') && $i->key('secret') ? new self($i) : null;
    }

    /** @return array{items: array<int, array<string, mixed>>, has_next: bool} */
    public function search(string $keyword, string $sort = 'sales', int $page = 1, int $limit = 30): array
    {
        $key = 'research:shopee:'.md5(mb_strtolower($keyword)."|{$sort}|{$page}|{$limit}");

        return Cache::remember($key, now()->addHours(6), function () use ($keyword, $sort, $page, $limit) {
            $query = 'query Offers($keyword: String, $sortType: Int, $page: Int, $limit: Int) {
                productOfferV2(keyword: $keyword, sortType: $sortType, page: $page, limit: $limit) {
                    nodes { itemId productName productLink offerLink imageUrl priceMin priceMax priceDiscountRate sales ratingStar commissionRate shopId shopName shopType }
                    pageInfo { page limit hasNextPage }
                }
            }';
            $data = $this->graphql($query, ['keyword' => $keyword, 'sortType' => self::SORT[$sort] ?? 2, 'page' => $page, 'limit' => $limit]);
            $offer = $data['productOfferV2'] ?? [];

            return [
                'items' => collect($offer['nodes'] ?? [])->map(fn ($n) => [
                    'id' => (string) $n['itemId'],
                    'name' => $n['productName'] ?? '',
                    'image' => $n['imageUrl'] ?? null,
                    'link' => $n['productLink'] ?? $n['offerLink'] ?? null,
                    'price_min' => (int) round((float) ($n['priceMin'] ?? 0)),
                    'price_max' => (int) round((float) ($n['priceMax'] ?? $n['priceMin'] ?? 0)),
                    'discount' => (int) ($n['priceDiscountRate'] ?? 0),
                    'sold' => (int) ($n['sales'] ?? 0),
                    'rating' => round((float) ($n['ratingStar'] ?? 0), 1),
                    'commission' => round((float) ($n['commissionRate'] ?? 0) * 100, 1),
                    'shop' => $n['shopName'] ?? null,
                    'shop_type' => self::shopType($n['shopType'] ?? []),
                    // Units sold × lowest price: a rough revenue estimate, not Shopee data.
                    'revenue_estimate' => (int) ($n['sales'] ?? 0) * (int) round((float) ($n['priceMin'] ?? 0)),
                ])->values()->all(),
                'has_next' => (bool) ($offer['pageInfo']['hasNextPage'] ?? false),
            ];
        });
    }

    public function verify(): void
    {
        $this->graphql('query { productOfferV2(keyword: "kaos", limit: 1) { nodes { itemId } } }', []);
    }

    private static function shopType(array|int|null $t): ?string
    {
        $t = (array) $t;

        return in_array(1, $t, true) ? 'Mall' : (in_array(2, $t, true) || in_array(4, $t, true) ? 'Star' : null);
    }

    /** Signature = SHA256(AppId + Timestamp + Payload + Secret). */
    private function graphql(string $query, array $variables): array
    {
        $payload = json_encode(['query' => $query, 'variables' => (object) $variables], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ts = time();
        $appId = $this->integration->key('app_id');
        $sig = hash('sha256', $appId.$ts.$payload.$this->integration->key('secret'));
        $res = Http::withHeaders([
            'Authorization' => "SHA256 Credential={$appId}, Timestamp={$ts}, Signature={$sig}",
            'Content-Type' => 'application/json',
        ])->timeout(20)->withBody($payload, 'application/json')->post(self::ENDPOINT);
        $json = $res->json() ?? [];
        if ($res->failed() || ! empty($json['errors'])) {
            $err = $json['errors'][0] ?? [];
            $code = $err['extensions']['code'] ?? null;
            throw new RuntimeException(match ((int) $code) {
                10020 => 'AppId/Secret Shopee Affiliate tidak valid.',
                10030 => 'Batas permintaan Shopee Affiliate tercapai, coba lagi sebentar.',
                10035 => 'Akun belum punya akses Open API Shopee Affiliate.',
                default => 'Shopee Affiliate: '.($err['message'] ?? "HTTP {$res->status()}"),
            });
        }

        return $json['data'] ?? [];
    }
}
