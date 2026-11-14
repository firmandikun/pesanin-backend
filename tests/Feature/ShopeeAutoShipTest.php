<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderAutomation;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Background auto "Atur Pengiriman" for Shopee, driven by the automation setting. */
class ShopeeAutoShipTest extends TestCase
{
    use RefreshDatabase;

    private function shop(User $user): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'is_demo' => false, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();

        return $shop;
    }

    public function test_ready_to_ship_orders_are_arranged_when_enabled(): void
    {
        $user = User::factory()->create(['settings' => ['automation' => ['auto_ship_shopee' => true, 'auto_ship_shopee_method' => 'auto']]]);
        $shop = $this->shop($user);
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'R1', 'order_status' => 'READY_TO_SHIP', 'item_list' => [], 'create_time' => time()]);
        // Advance Fulfilment order must be left alone.
        $sync->upsertOrder($shop, ['order_sn' => 'AF', 'order_status' => 'READY_TO_SHIP', 'fulfillment_flag' => 'fulfilled_by_shopee', 'item_list' => [], 'create_time' => time()]);

        $shipped = [];
        Http::fake(function ($req) use (&$shipped) {
            $url = $req->url();
            if (str_contains($url, 'get_shipping_parameter')) {
                return Http::response(['response' => ['info_needed' => ['pickup' => ['address_id']], 'pickup' => ['address_list' => [[
                    'address_id' => 99, 'address_flag' => ['pickup_address'], 'time_slot_list' => [['pickup_time_id' => 'T1']],
                ]]]]]);
            }
            if (str_contains($url, 'ship_order')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $shipped[] = true;

                return Http::response(['response' => []]);
            }
            if (str_contains($url, 'get_tracking_number')) {
                return Http::response(['response' => ['tracking_number' => 'SPX-AUTO']]);
            }
            if (str_contains($url, 'get_order_detail')) {
                return Http::response(['response' => ['order_list' => [['order_sn' => 'R1', 'order_status' => 'PROCESSED', 'item_list' => [], 'create_time' => time()]]]]);
            }

            return Http::response(['response' => []]);
        });

        $n = OrderAutomation::shopeeAutoShip();

        $this->assertSame(1, $n);
        $this->assertNotEmpty($shipped);
        $this->assertSame('SPX-AUTO', Order::where('external_order_sn', 'R1')->value('tracking_number'));
        // The Advance Fulfilment order was never arranged.
        $this->assertNull(Order::where('external_order_sn', 'AF')->value('tracking_number'));
    }

    public function test_disabled_by_default(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        (new ShopeeSync)->upsertOrder($shop, ['order_sn' => 'R1', 'order_status' => 'READY_TO_SHIP', 'item_list' => [], 'create_time' => time()]);
        Http::fake(['*' => Http::response(['response' => []])]);

        $this->assertSame(0, OrderAutomation::shopeeAutoShip());
        Http::assertNothingSent();
    }
}
