<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderAutomation;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Shopee "Tertunda" (PENDING: paid, still under Shopee review): its own sub-tab inside Perlu Dikirim, never arranged
 * automatically, and arranged as soon as Shopee releases it (READY_TO_SHIP), even after earlier failed attempts.
 */
class ShopeePendingOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create(['settings' => ['automation' => ['auto_ship_shopee' => true, 'auto_ship_shopee_method' => 'auto']]]);
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'is_demo' => false, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $this->user->id;
        $this->shop->save();
    }

    public function test_order_detail_asks_shopee_for_pending_status(): void
    {
        Http::fake(['*get_order_detail*' => Http::response(['response' => ['order_list' => []]])]);
        (new \App\Support\Shopee\ShopeeClient)->orderDetail($this->shop, ['P1']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'get_order_detail') && str_contains($r->url(), 'request_order_status_pending=true'));
    }

    public function test_pending_order_has_its_own_tab_and_is_not_auto_arranged(): void
    {
        $sync = new ShopeeSync;
        $sync->upsertOrder($this->shop, ['order_sn' => 'P1', 'order_status' => 'PENDING', 'pay_time' => time(), 'item_list' => [], 'create_time' => time()]);
        $sync->upsertOrder($this->shop, ['order_sn' => 'R1', 'order_status' => 'PROCESSED', 'pay_time' => time(), 'item_list' => [], 'create_time' => time()]);
        Http::fake(fn () => Http::response(['response' => []]));

        $this->assertSame(0, OrderAutomation::shopeeAutoShip());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ship_order') || str_contains($r->url(), 'get_shipping_parameter'));

        Sanctum::actingAs($this->user);
        $count = fn (string $s) => $this->getJson("/api/orders?channel=shopee&shopee_state={$s}&per_page=1")->assertOk()->json('stats.total_orders');
        $this->assertSame(1, $count('pending'));
        $this->assertSame(0, $count('to_ship'));    // not "Perlu diproses"
        $this->assertSame(2, $count('perlu_kirim')); // but counted in "Perlu Dikirim", as in Seller Centre
        $this->assertSame(['processing', 'not_shipped'], [Order::firstWhere('external_order_sn', 'P1')->status, Order::firstWhere('external_order_sn', 'P1')->shipping_status]);
    }

    public function test_released_order_gets_fresh_auto_arrange_attempts(): void
    {
        $sync = new ShopeeSync;
        $order = $sync->upsertOrder($this->shop, ['order_sn' => 'P1', 'order_status' => 'PENDING', 'pay_time' => time(), 'item_list' => [], 'create_time' => time()]);
        $order->update(['auto_ship_tries' => OrderAutomation::MAX_TRIES, 'auto_ship_error' => 'bukan pesanan siap kirim (PENDING)']);

        $sync->upsertOrder($this->shop, ['order_sn' => 'P1', 'order_status' => 'READY_TO_SHIP', 'pay_time' => time(), 'item_list' => [], 'create_time' => time()]);
        $order->refresh();
        $this->assertSame(['READY_TO_SHIP', 0, null], [$order->external_status, (int) $order->auto_ship_tries, $order->auto_ship_error]);
    }
}
