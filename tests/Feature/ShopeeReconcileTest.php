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
}
