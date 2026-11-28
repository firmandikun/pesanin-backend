<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cek promo aktif per produk: ongoing/upcoming promotions per item, read from the promotion modules. */
class ShopeeActivePromotionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_items_to_their_active_promotions(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 777, 'shop_name' => 'Glow', 'status' => 'active',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'access_expires_at' => now()->addHours(3)]);
        $now = time();
        $ok = fn (array $r) => Http::response(['error' => '', 'message' => '', 'response' => $r]);
        Http::fake(function ($req) use ($ok, $now) {
            $path = parse_url($req->url(), PHP_URL_PATH);

            return match ($path) {
                '/api/v2/discount/get_discount_list' => $ok(['discount_list' => [
                    ['discount_id' => 11, 'discount_name' => 'Payday', 'start_time' => $now - 3600, 'end_time' => $now + 86400],
                    ['discount_id' => 12, 'discount_name' => 'Lama', 'start_time' => $now - 9 * 86400, 'end_time' => $now - 86400], // expired: ignored
                ]]),
                '/api/v2/discount/get_discount' => $ok(['item_list' => [['item_id' => 1001, 'model_list' => [['model_id' => 5]]], ['item_id' => 1002]]]),
                '/api/v2/shop_flash_sale/get_shop_flash_sale_list' => $ok(['flash_sale_list' => [['flash_sale_id' => 21, 'start_time' => $now + 3600, 'end_time' => $now + 7200, 'status' => 1]]]),
                '/api/v2/shop_flash_sale/get_shop_flash_sale_items' => $ok(['item_info' => [['item_id' => 1001]], 'models' => [['item_id' => 1001, 'model_id' => 5]]]),
                '/api/v2/bundle_deal/get_bundle_deal_list' => Http::response(['error' => 'error_permission', 'message' => 'no access'], 403),
                '/api/v2/add_on_deal/get_add_on_deal_list' => $ok(['add_on_deal_list' => []]),
                '/api/v2/voucher/get_voucher_list' => $ok(['voucher_list' => [
                    ['voucher_id' => 31, 'voucher_name' => 'Hemat 5rb', 'voucher_type' => 1, 'reward_type' => 1, 'discount_amount' => 5000, 'start_time' => $now - 60, 'end_time' => $now + 600],
                    ['voucher_id' => 32, 'voucher_name' => 'Kabel 10%', 'voucher_type' => 2, 'reward_type' => 2, 'percentage' => 10, 'start_time' => $now - 60, 'end_time' => $now + 600],
                ]]),
                '/api/v2/voucher/get_voucher' => $ok(['voucher_id' => 32, 'item_id_list' => [1002]]),
                default => $ok([]),
            };
        });

        Sanctum::actingAs($user);
        $r = $this->getJson('/api/marketplace/listings/active-promotions')->assertOk()->json();

        $promos = fn (string $k) => array_map(fn ($p) => "{$p['label']}:{$p['status']}", $r['items'][$k] ?? []);
        $this->assertSame(['Promo Toko:ongoing', 'Flash Sale:upcoming'], $promos("{$shop->id}:1001"));
        $this->assertSame(['Promo Toko:ongoing', 'Voucher Produk:ongoing'], $promos("{$shop->id}:1002"));
        $this->assertSame(['Hemat 5rb'], array_column($r['shop_wide'][$shop->id], 'name'));
        $this->assertCount(1, $r['errors']);
        $this->assertStringContainsString('Paket Diskon', $r['errors'][0]);

        // Cached: a second read does not call Shopee again.
        $calls = count(Http::recorded());
        $this->getJson('/api/marketplace/listings/active-promotions')->assertOk();
        $this->assertSame($calls, count(Http::recorded()));
    }
}
