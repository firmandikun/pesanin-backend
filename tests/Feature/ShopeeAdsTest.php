<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Shopee product-ads campaigns: sync from Ads API (faked) and the per-campaign / per-product report. */
class ShopeeAdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaigns_and_products_with_spend(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        foreach ([[111, 'Serum'], [222, 'Toner']] as [$id, $name]) {
            $l = new MarketplaceListing(['marketplace_shop_id' => $shop->id, 'item_id' => $id, 'model_id' => 0, 'name' => $name, 'price' => 1000, 'stock' => 1, 'status' => 'NORMAL']);
            $l->user_id = $user->id;
            $l->save();
        }
        $d = fn ($n) => now()->subDays($n)->format('d-m-Y');
        Http::fake([
            '*get_total_balance*' => Http::response(['response' => ['data_timestamp' => time(), 'total_balance' => 125000.5]]),
            '*get_all_cpc_ads_daily_performance*' => Http::response(['response' => [['date' => $d(1), 'expense' => 15000], ['date' => $d(0), 'expense' => 5000]]]),
            '*get_product_level_campaign_id_list*' => Http::response(['response' => ['has_next_page' => false, 'campaign_list' => [['campaign_id' => 501, 'ad_type' => 'manual'], ['campaign_id' => 502, 'ad_type' => 'auto']]]]),
            '*get_product_level_campaign_setting_info*' => Http::response(['response' => ['campaign_list' => [
                ['campaign_id' => 501, 'common_info' => ['ad_type' => 'manual', 'ad_name' => 'Iklan Serum', 'campaign_status' => 'ongoing', 'bidding_method' => 'auto', 'campaign_budget' => 50000, 'item_id_list' => [111]]],
                ['campaign_id' => 502, 'common_info' => ['ad_type' => 'auto', 'ad_name' => 'Iklan Otomatis', 'campaign_status' => 'paused', 'item_id_list' => []], 'auto_product_ads_info' => [['product_name' => 'Serum', 'status' => 'ongoing', 'item_id' => 111], ['product_name' => 'Toner', 'status' => 'ongoing', 'item_id' => 222]]],
            ]]]),
            '*get_product_campaign_daily_performance*' => Http::response(['response' => [['shop_id' => 9, 'region' => 'ID', 'campaign_list' => [
                ['campaign_id' => 501, 'metrics_list' => [['date' => $d(1), 'impression' => 1000, 'clicks' => 50, 'expense' => 10000, 'broad_gmv' => 80000, 'broad_order' => 2, 'broad_order_amount' => 3]]],
                ['campaign_id' => 502, 'metrics_list' => [['date' => $d(0), 'impression' => 400, 'clicks' => 20, 'expense' => 10000, 'broad_gmv' => 0, 'broad_order' => 0]]],
            ]]]]),
        ]);

        (new ShopeeSync)->ads($shop);
        (new ShopeeSync)->ads($shop); // idempotent

        $r = $this->getJson('/api/marketplace/ads')->assertOk()->json();
        $this->assertSame(20000, $r['total']['expense']);
        $this->assertSame(80000, $r['total']['gmv']);
        $this->assertSame(['Iklan Serum', 'Iklan Otomatis'], array_column($r['campaigns'], 'name'));
        $this->assertSame(['Serum', 'Toner'], array_column($r['campaigns'][1]['items'], 'name'));
        // Serum: 10000 own + half of 10000 shared = 15000; Toner: 5000.
        $this->assertSame([['Serum', 15000, true], ['Toner', 5000, true]], array_map(fn ($p) => [$p['name'], $p['expense'], $p['shared']], $r['products']));
        $this->assertCount(2, $r['daily']);
        $this->assertSame(['amount' => 125001, 'shops' => 1], array_intersect_key($r['balance'], ['amount' => 0, 'shops' => 0]));
        $this->assertFalse($r['balance']['stale']);
        // Filtering by a shop the user doesn't have returns no balance.
        $this->assertSame(0, $this->getJson('/api/marketplace/ads?shop=999999')->json('balance.shops'));
        $this->assertSame('auto', $r['campaigns'][0]['bidding_method']);
        $this->assertSame(3, \App\Models\ShopeeAdStat::sum('items_sold'));
    }

    public function test_balance_is_read_live_from_shopee_when_page_opens(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shops = collect([1, 2])->map(function ($n) use ($user) {
            $s = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => $n, 'shop_name' => "T{$n}", 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30), 'ads_balance' => 999]);
            $s->user_id = $user->id;
            $s->save();

            return $s;
        });
        Http::fake(['*get_total_balance*' => fn ($r) => Http::response(['response' => ['total_balance' => $r['shop_id'] == 1 ? 40000 : 10000]])]);

        $this->assertSame(50000, $this->getJson('/api/marketplace/ads')->json('balance.amount'));
        $this->assertSame(10000, $this->getJson('/api/marketplace/ads?shop='.$shops[1]->id)->json('balance.amount'));
        $this->assertSame(40000, $shops[0]->fresh()->ads_balance);
        Http::assertSentCount(2); // second request served from the 60s cache
    }
}
