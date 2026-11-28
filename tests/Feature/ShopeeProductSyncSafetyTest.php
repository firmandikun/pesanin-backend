<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Listings hold HPP and Stok Master links: a blank product-list reply must never wipe them. */
class ShopeeProductSyncSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function shopWithListing(): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 5, 'shop_name' => 'T', 'status' => 'active',
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()]);
        MarketplaceListing::create(['user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'item_id' => 1, 'model_id' => 0, 'name' => 'Kabel', 'price' => 10000, 'stock' => 3, 'cost_price' => 6000]);

        return $shop;
    }

    public function test_blank_reply_keeps_listings_and_hpp(): void
    {
        $shop = $this->shopWithListing();
        Http::fake(['*' => Http::response(['error' => '', 'response' => []])]);
        (new ShopeeSync)->products($shop);
        $this->assertSame(6000, MarketplaceListing::sole()->cost_price);
    }

    public function test_shop_reported_empty_removes_listings(): void
    {
        $shop = $this->shopWithListing();
        Http::fake(['*' => Http::response(['error' => '', 'response' => ['item' => [], 'total_count' => 0, 'has_next_page' => false]])]);
        (new ShopeeSync)->products($shop);
        $this->assertSame(0, MarketplaceListing::count());
    }
}
