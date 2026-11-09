<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** After "Atur pengiriman", a stale READY_TO_SHIP from Shopee must not move the order back. */
class ShopeeArrangedStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_arranged_order_stays_processed_on_stale_read(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['response' => []])]);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        $detail = ['order_sn' => 'SN1', 'order_status' => 'READY_TO_SHIP', 'item_list' => [], 'create_time' => time()];
        $order = (new ShopeeSync)->upsertOrder($shop, $detail);
        $this->assertSame('READY_TO_SHIP', $order->external_status);

        ShopeeSync::markArranged($order);
        $this->assertSame('PROCESSED', $order->fresh()->external_status);
        $this->assertSame('PROCESSED', (new ShopeeSync)->upsertOrder($shop, $detail)->external_status);

        // A real later status from Shopee still wins.
        $this->assertSame('SHIPPED', (new ShopeeSync)->upsertOrder($shop, ['order_status' => 'SHIPPED'] + $detail)->external_status);
        $this->assertSame(1, Order::count());
    }
}
