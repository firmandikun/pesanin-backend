<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Product sync stores the parent item SKU ("SKU Induk") on every variation row. */
class ShopeeProductSyncTest extends TestCase
{
    use RefreshDatabase;

    private function shop(User $user): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active',
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();

        return $shop;
    }

    public function test_parent_and_variation_skus_are_stored(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);

        Http::fake(function ($req) {
            $url = $req->url();
            if (str_contains($url, 'get_item_list')) {
                return Http::response(['response' => ['item' => [['item_id' => 777]], 'has_next_page' => false, 'next_offset' => 0]]);
            }
            if (str_contains($url, 'get_item_base_info')) {
                return Http::response(['response' => ['item_list' => [[
                    'item_id' => 777, 'item_name' => 'Kaos', 'item_sku' => 'INDUK-1', 'item_status' => 'NORMAL', 'has_model' => true,
                    'image' => ['image_url_list' => ['http://img']],
                ]]]]);
            }
            if (str_contains($url, 'get_model_list')) {
                return Http::response(['response' => [
                    'tier_variation' => [['name' => 'Warna', 'option_list' => [['option' => 'Merah'], ['option' => 'Biru']]]],
                    'model' => [
                        ['model_id' => 1, 'tier_index' => [0], 'model_sku' => 'VAR-MERAH', 'price_info' => [['current_price' => 50000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 5]]],
                        ['model_id' => 2, 'tier_index' => [1], 'model_sku' => 'VAR-BIRU', 'price_info' => [['current_price' => 50000]], 'stock_info_v2' => ['summary_info' => ['total_available_stock' => 3]]],
                    ],
                ]]);
            }

            return Http::response(['response' => []]);
        });

        $n = (new ShopeeSync)->products($shop);
        $this->assertSame(2, $n);

        $rows = MarketplaceListing::where('marketplace_shop_id', $shop->id)->orderBy('model_id')->get();
        $this->assertCount(2, $rows);
        // Both variations carry the parent SKU plus their own model SKU.
        $this->assertSame('INDUK-1', $rows[0]->item_sku);
        $this->assertSame('VAR-MERAH', $rows[0]->sku);
        $this->assertSame('INDUK-1', $rows[1]->item_sku);
        $this->assertSame('VAR-BIRU', $rows[1]->sku);
    }

    /** Orders synced before the product had a SKU still get SKU Induk/Variasi in the list (filled at read time). */
    public function test_order_list_fills_skus_from_listing_at_read_time(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        (new ShopeeSync)->upsertOrder($shop, ['order_sn' => 'OLD', 'order_status' => 'PROCESSED', 'create_time' => time(), 'item_list' => [
            ['item_id' => 777, 'model_id' => 9, 'item_name' => 'Kaos', 'model_quantity_purchased' => 1],
        ]]);
        // The product gets its SKUs only afterwards.
        MarketplaceListing::create([
            'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'item_id' => 777, 'model_id' => 9,
            'name' => 'Kaos', 'item_sku' => 'INDUK-1', 'sku' => 'VAR-BIRU', 'price' => 50000, 'stock' => 3, 'status' => 'NORMAL',
        ]);

        $item = $this->actingAs($user)->getJson('/api/orders?channel=shopee')->assertOk()->json('orders.data.0.items.0');
        $this->assertSame('INDUK-1', $item['item_sku']);
        $this->assertSame('VAR-BIRU', $item['model_sku']);
    }

    /** When Shopee's order snapshot has no SKU, the item takes the SKU from the matched product listing. */
    public function test_order_item_falls_back_to_listing_sku(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        MarketplaceListing::create([
            'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'item_id' => 777, 'model_id' => 9,
            'name' => 'Kaos', 'item_sku' => 'INDUK-1', 'sku' => 'VAR-BIRU', 'price' => 50000, 'stock' => 3, 'status' => 'NORMAL',
        ]);

        // Shopee returns the order item with empty item_sku/model_sku.
        (new ShopeeSync)->upsertOrder($shop, ['order_sn' => 'O1', 'order_status' => 'READY_TO_SHIP', 'create_time' => time(), 'item_list' => [
            ['item_id' => 777, 'model_id' => 9, 'item_name' => 'Kaos', 'item_sku' => '', 'model_sku' => '', 'model_quantity_purchased' => 1],
        ]]);

        $items = Order::where('external_order_sn', 'O1')->value('items');
        $this->assertSame('INDUK-1', $items[0]['item_sku']);
        $this->assertSame('VAR-BIRU', $items[0]['model_sku']);
    }
}
