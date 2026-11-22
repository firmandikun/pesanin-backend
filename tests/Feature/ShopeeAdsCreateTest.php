<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\ShopeeAdCampaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopeeAdsCreateTest extends TestCase
{
    use RefreshDatabase;

    private function seedShop(): array
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $shop = new MarketplaceShop([
            'platform' => 'shopee',
            'shop_id' => 9001,
            'shop_name' => 'Toko Test',
            'status' => 'active',
            'is_demo' => false,
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => now()->addDays(30),
        ]);
        $shop->user_id = $user->id;
        $shop->save();

        foreach ([[111, 'Serum Wajah'], [222, 'Toner Hydrating']] as [$itemId, $name]) {
            $l = new MarketplaceListing([
                'marketplace_shop_id' => $shop->id,
                'item_id' => $itemId,
                'model_id' => 0,
                'name' => $name,
                'sku' => 'SKU-'.$itemId,
                'price' => 45000,
                'stock' => 12,
                'status' => 'NORMAL',
                'image' => 'https://img.example/'.$itemId.'.jpg',
            ]);
            $l->user_id = $user->id;
            $l->save();
        }

        ShopeeAdCampaign::create([
            'user_id' => $user->id,
            'marketplace_shop_id' => $shop->id,
            'campaign_id' => 7001,
            'name' => 'Existing Ad',
            'status' => 'ongoing',
            'ad_type' => 'manual',
            'item_ids' => [222],
        ]);

        return [$user, $shop];
    }

    public function test_options_and_suggest_endpoints(): void
    {
        [, $shop] = $this->seedShop();

        Http::fake([
            '*get_recommended_item_list*' => Http::response(['response' => ['item_list' => [['item_id' => 111]]]]),
            '*get_product_recommended_budget*' => Http::response(['response' => ['suggested_budget' => 50000]]),
            '*get_recommended_roi_target*' => Http::response(['response' => ['recommended_roi_target' => 6.4]]),
        ]);

        $this->getJson('/api/marketplace/ads/create/options?shop_id='.$shop->id)
            ->assertOk()
            ->assertJsonPath('shop_id', $shop->id)
            ->assertJsonCount(1, 'shops')
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.item_id', 111)
            ->assertJsonPath('products.0.recommended', true)
            ->assertJsonPath('products.1.already_advertised', true);

        $this->getJson('/api/marketplace/ads/create/suggest?shop_id='.$shop->id.'&item_id=111&bidding_method=auto')
            ->assertOk()
            ->assertJson([
                'budget_suggested' => 50000,
                'roi_suggested' => 6.4,
            ]);
    }

    public function test_store_individual_roas_and_group_unlimited(): void
    {
        [, $shop] = $this->seedShop();

        Http::fake([
            '*create_manual_product_ads*' => Http::response(['response' => ['campaign_id' => 8801]]),
            '*get_total_balance*' => Http::response(['response' => ['total_balance' => 100000]]),
            '*get_all_cpc_ads_daily_performance*' => Http::response(['response' => []]),
            '*get_product_level_campaign_id_list*' => Http::response(['response' => ['has_next_page' => false, 'campaign_list' => []]]),
        ]);

        $this->postJson('/api/marketplace/ads/create', [
            'shop_id' => $shop->id,
            'ad_mode' => 'individual',
            'item_ids' => [111, 222],
            'bidding_method' => 'roas',
            'budget_mode' => 'daily',
            'budget' => 50000,
            'targets' => ['111' => 6.2, '222' => 5.0],
        ])->assertOk()->assertJson([
            'ok' => true,
            'campaign_ids' => [8801, 8801],
        ]);

        $this->postJson('/api/marketplace/ads/create', [
            'shop_id' => $shop->id,
            'ad_mode' => 'group',
            'item_ids' => [111, 222],
            'bidding_method' => 'auto',
            'budget_mode' => 'unlimited',
        ])->assertOk()->assertJson([
            'ok' => true,
            'campaign_ids' => [8801],
        ]);
    }
}
