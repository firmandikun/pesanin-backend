<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Riset Pasar with faked HTTP: Shopee Affiliate (signed GraphQL) and SerpApi Google Trends. */
class MarketResearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        // No shared Pesanin keys unless a test sets them.
        config(['services.serpapi.key' => null, 'services.shopee_affiliate.app_id' => null, 'services.shopee_affiliate.secret' => null]);
    }

    private function connect(string $provider, array $creds): void
    {
        UserIntegration::create(['user_id' => $this->user->id, 'provider' => $provider, 'credentials' => $creds, 'webhook_token' => Str::random(40), 'status' => 'active']);
    }

    public function test_requires_connection(): void
    {
        $this->getJson('/api/research/status')->assertJson(['shopee' => false, 'trends' => false]);
        $this->getJson('/api/research/shopee?keyword=serum')->assertStatus(422);
    }

    public function test_shopee_affiliate_search_is_signed_and_summarised(): void
    {
        $this->connect('shopee_affiliate', ['app_id' => '123', 'secret' => 'sek']);
        Http::fake(['open-api.affiliate.shopee.co.id/graphql' => Http::response(['data' => ['productOfferV2' => [
            'nodes' => [
                ['itemId' => 1, 'productName' => 'Serum Vit C A', 'productLink' => 'https://shopee.co.id/a', 'imageUrl' => 'i', 'priceMin' => '45000', 'priceMax' => '60000', 'sales' => 1200, 'ratingStar' => '4.9', 'commissionRate' => '0.05', 'shopName' => 'Toko A', 'shopType' => [1]],
                ['itemId' => 2, 'productName' => 'Serum Vit C B', 'productLink' => 'https://shopee.co.id/b', 'imageUrl' => 'i', 'priceMin' => '30000', 'priceMax' => '30000', 'sales' => 800, 'ratingStar' => '4.7', 'commissionRate' => '0.1', 'shopName' => 'Toko B', 'shopType' => []],
            ],
            'pageInfo' => ['page' => 1, 'limit' => 30, 'hasNextPage' => true],
        ]]])]);

        $res = $this->getJson('/api/research/shopee?keyword=serum%20vitamin%20c&sort=sales')->assertOk()->json();
        $this->assertSame(2000, $res['summary']['sold']);
        $this->assertSame(1200 * 45000 + 800 * 30000, $res['summary']['revenue_estimate']);
        $this->assertSame(50, $res['summary']['mall_share']);
        $this->assertSame(['Mall', null], array_column($res['items'], 'shop_type'));
        $this->assertSame(5.0, (float) $res['items'][0]['commission']);
        // Product analysis: price bands, sweet spot, market concentration, commission range.
        $a = $res['analysis'];
        $this->assertSame(2, count($a['price_bands']));
        $this->assertSame(['min' => 37500, 'max' => 45000, 'products' => 1, 'sold' => 1200, 'sold_share' => 60], $a['best_band']);
        $this->assertSame(100, $a['top3_share']);
        $this->assertSame(40, $a['non_mall_share']);
        $this->assertSame([5, 5, 10], [(int) $a['commission']['min'], (int) $a['commission']['median'], (int) $a['commission']['max']]);
        Http::assertSent(function ($r) {
            preg_match('/Credential=(\d+), Timestamp=(\d+), Signature=([a-f0-9]{64})/', $r->header('Authorization')[0], $m);

            return $m && $m[3] === hash('sha256', '123'.$m[2].$r->body().'sek') && json_decode($r->body(), true)['variables']['sortType'] === 2;
        });
    }

    public function test_google_trends_interest_and_related(): void
    {
        $this->connect('serpapi', ['api_key' => 'serp']);
        Http::fake(['serpapi.com/search.json*' => fn ($r) => Http::response(match ($r['data_type']) {
            'TIMESERIES' => ['interest_over_time' => ['timeline_data' => collect(range(1, 8))->map(fn ($i) => ['date' => "W{$i}", 'timestamp' => (string) (1700000000 + $i * 604800), 'values' => [
                ['query' => 'serum', 'extracted_value' => $i * 10], ['query' => 'toner', 'extracted_value' => 50],
            ]])->all()]],
            'RELATED_QUERIES' => ['related_queries' => ['rising' => [['query' => 'serum niacinamide', 'value' => 'Breakout', 'extracted_value' => 5000]], 'top' => [['query' => 'serum wajah', 'value' => '100', 'extracted_value' => 100]]]],
            'GEO_MAP_0' => ['interest_by_region' => [['location' => 'Jawa Barat', 'extracted_value' => 100], ['location' => 'Bali', 'extracted_value' => 0]]],
        })]);

        $t = $this->getJson('/api/research/trends?q=serum,toner&date=today%2012-m')->assertOk()->json();
        $this->assertCount(8, $t['points']);
        $this->assertSame(['serum', 'toner'], array_column($t['summary'], 'keyword'));
        $this->assertSame(400, $t['summary'][0]['change']); // first quarter avg 15 → last quarter avg 75
        $rel = $this->getJson('/api/research/trends/related?q=serum')->assertOk()->json();
        $this->assertSame('serum niacinamide', $rel['rising'][0]['query']);
        $this->assertSame([['region' => 'Jawa Barat', 'value' => 100]], $rel['regions']);
        Http::assertSent(fn ($r) => $r['engine'] === 'google_trends' && $r['geo'] === 'ID' && $r['api_key'] === 'serp');
    }

    public function test_shared_pesanin_key_works_without_signup_and_is_metered_per_plan(): void
    {
        config(['services.serpapi.key' => 'pesanin-key']);
        Http::fake(['serpapi.com/search.json*' => Http::response(['interest_over_time' => ['timeline_data' => [
            ['date' => 'W1', 'timestamp' => '1700000000', 'values' => [['query' => 'kaos', 'extracted_value' => 40]]],
        ]]])]);

        $this->getJson('/api/research/status')->assertJson(['trends' => true, 'source' => ['trends' => 'pesanin'], 'quota' => ['limit' => 5, 'used' => 0]]);
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/research/trends?q=kaos')->assertOk();
        }
        $this->getJson('/api/research/trends?q=kaos')->assertStatus(402)->assertJson(['limit' => 'research']);
        $this->getJson('/api/research/status')->assertJson(['quota' => ['used' => 5]]);
        Http::assertSent(fn ($r) => $r['api_key'] === 'pesanin-key');

        // A seller's own key overrides the shared one and is not metered.
        $this->connect('serpapi', ['api_key' => 'own-key']);
        $this->user->unsetRelation('integrations'); // each real request loads the user fresh
        $this->getJson('/api/research/trends?q=kaos')->assertOk();
        $this->getJson('/api/research/status')->assertJson(['source' => ['trends' => 'own']]);
    }
}
