<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Siap diserahkan" must match Seller Centre even when the first sync was cut short. */
class ShopeeReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_open_orders_are_pulled_and_stale_ones_refreshed(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        $sync = new ShopeeSync;
        // Locally still "Siap diserahkan", but Shopee has shipped it since.
        $sync->upsertOrder($shop, ['order_sn' => 'OLD', 'order_status' => 'PROCESSED', 'item_list' => [], 'create_time' => time() - 86400]);

        $requestedStatuses = [];
        Http::fake(function ($req) use (&$requestedStatuses) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_contains($req->url(), 'get_order_list')) {
                $requestedStatuses[] = $q['order_status'] ?? null;
                $list = ($q['order_status'] ?? '') === 'PROCESSED' && ($q['time_to'] ?? 0) >= time() - 5 ? [['order_sn' => 'NEW']] : [];

                return Http::response(['response' => ['order_list' => $list, 'more' => false]]);
            }
            if (str_contains($req->url(), 'get_order_detail')) {
                $sns = explode(',', $q['order_sn_list']);

                return Http::response(['response' => ['order_list' => array_map(fn ($sn) => [
                    'order_sn' => $sn, 'order_status' => $sn === 'NEW' ? 'PROCESSED' : 'SHIPPED', 'item_list' => [], 'create_time' => time() - 3600,
                ], $sns)]]);
            }

            return Http::response(['response' => []]);
        });

        $sync->reconcileOpen($shop);

        $this->assertSame('PROCESSED', Order::where('external_order_sn', 'NEW')->value('external_status'));
        $this->assertSame('SHIPPED', Order::where('external_order_sn', 'OLD')->value('external_status'));
        $this->assertNotContains('RETRY_SHIP', $requestedStatuses);
    }

    /** An open order synced before item SKUs existed gets its detail refreshed so the AWB can print SKU Induk/Variasi. */
    public function test_open_orders_without_item_sku_are_refreshed(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'SKUO', 'order_status' => 'PROCESSED', 'item_list' => [], 'create_time' => time() - 3600]);
        // Simulate a legacy row: items JSON has no "item_sku" key, and payment_channel is already filled.
        Order::where('external_order_sn', 'SKUO')->update([
            'items' => [['item_id' => 1, 'model_id' => 2, 'name' => 'X', 'sku' => 'VAR-1', 'qty' => 1, 'price' => 1000]],
            'payment_channel' => 'Shopee',
        ]);

        Http::fake(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            // Keep it listed under its current status so it is neither "refresh" (status unchanged) nor "stale".
            if (str_contains($req->url(), 'get_order_list')) {
                $list = ($q['order_status'] ?? '') === 'PROCESSED' ? [['order_sn' => 'SKUO', 'order_status' => 'PROCESSED']] : [];

                return Http::response(['response' => ['order_list' => $list, 'more' => false]]);
            }
            if (str_contains($req->url(), 'get_order_detail')) {
                return Http::response(['response' => ['order_list' => array_map(fn ($sn) => [
                    'order_sn' => $sn, 'order_status' => 'PROCESSED', 'create_time' => time() - 3600, 'payment_method' => 'COD',
                    'item_list' => [['item_id' => 1, 'model_id' => 2, 'item_name' => 'X', 'item_sku' => 'INDUK-1', 'model_sku' => 'VAR-1', 'model_quantity_purchased' => 1]],
                ], explode(',', $q['order_sn_list']))]]);
            }

            return Http::response(['response' => []]);
        });

        $sync->reconcileOpen($shop);

        $items = Order::where('external_order_sn', 'SKUO')->value('items');
        $this->assertSame('INDUK-1', $items[0]['item_sku']);
        $this->assertSame('VAR-1', $items[0]['model_sku']);
    }

    public function test_old_parcels_still_on_the_way_are_pulled_and_returns_stay_in_dikirim(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'RET', 'order_status' => 'TO_RETURN', 'item_list' => [], 'create_time' => time()]);
        $oldest = null;
        Http::fake(function ($req) use (&$oldest) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_contains($req->url(), 'get_order_list')) {
                if (($q['order_status'] ?? '') === 'SHIPPED') {
                    $oldest = min($oldest ?? PHP_INT_MAX, (int) $q['time_from']);
                }
                // A 25-day-old parcel, outside the first 15-day sync, still on its way.
                $hit = ($q['order_status'] ?? '') === 'SHIPPED' && $q['time_from'] <= time() - 25 * 86400 && $q['time_to'] >= time() - 25 * 86400;

                return Http::response(['response' => ['order_list' => $hit ? [['order_sn' => 'OLDSHIP', 'order_status' => 'SHIPPED']] : [], 'more' => false]]);
            }
            if (str_contains($req->url(), 'get_order_detail')) {
                return Http::response(['response' => ['order_list' => array_map(fn ($sn) => ['order_sn' => $sn, 'order_status' => $sn === 'RET' ? 'TO_RETURN' : 'SHIPPED', 'item_list' => [], 'create_time' => time() - 25 * 86400], explode(',', $q['order_sn_list']))]]);
            }

            return Http::response(['response' => []]);
        });

        $sync->reconcileOpen($shop);

        $this->assertLessThanOrEqual(time() - 59 * 86400, $oldest);
        $sns = collect($this->actingAs($user)->getJson('/api/orders?channel=shopee&shopee_state=shipping')->json('orders.data'))->pluck('external_order_sn')->sort()->values()->all();
        $this->assertSame(['OLDSHIP', 'RET'], $sns);
        $this->assertSame([], $this->getJson('/api/orders?channel=shopee&shopee_state=cancelled')->json('orders.data'));
    }

    public function test_same_shopee_shop_in_two_accounts_does_not_collide(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        Http::fake(['*' => Http::response(['response' => []])]);
        $make = function () {
            $u = User::factory()->create();
            $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 51988029, 'shop_name' => 'M storeku', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
            $shop->user_id = $u->id;
            $shop->save();

            return $shop;
        };
        [$a, $b] = [$make(), $make()];
        $detail = ['order_sn' => '2611137P07CAXU', 'order_status' => 'SHIPPED', 'item_list' => [], 'create_time' => time()];
        $sync = new ShopeeSync;

        $first = $sync->upsertOrder($a, $detail);
        $second = $sync->upsertOrder($b, $detail);
        $this->assertSame('SP2611137P07CAXU', $first->code);
        $this->assertSame('SP2611137P07CAXU-'.$b->id, $second->code);
        // Re-syncing keeps each account's code.
        $this->assertSame($second->code, $sync->upsertOrder($b, $detail)->code);
        $this->assertSame(2, Order::count());
    }

    public function test_a_code_taken_meanwhile_does_not_break_the_sync(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        Http::fake(['*' => Http::response(['response' => []])]);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        // Another row grabs the code after orderCode() looked (simulated by a manual order using it).
        Order::creating(function ($o) {
            if ($o->code === 'SPRACE1' && ! Order::where('code', 'SPRACE1')->exists()) {
                \Illuminate\Support\Facades\DB::table('orders')->insert(['user_id' => $o->user_id, 'code' => 'SPRACE1', 'customer_name' => 'x', 'phone' => '0', 'quantity' => 1, 'unit_price' => 0, 'subtotal' => 0, 'total' => 0, 'payment_status' => 'paid', 'status' => 'pending', 'shipping_status' => 'not_shipped', 'source' => 'manual', 'channel' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $order = (new ShopeeSync)->upsertOrder($shop, ['order_sn' => 'RACE1', 'order_status' => 'SHIPPED', 'item_list' => [], 'create_time' => time()]);
        $this->assertSame('SPRACE1-'.$shop->id, $order->code);
        $this->assertTrue($order->exists);
    }
}
