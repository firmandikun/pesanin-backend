<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\ShopeeBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The dashboard "Resi siap dicetak" count matches the orders page "Telah diproses": processed orders + bookings. */
class DashboardShopeeCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_to_print_includes_processed_bookings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();

        $order = function (string $st, ?string $tn) use ($user, $shop) {
            $o = new Order([
                'channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id,
                'external_order_sn' => uniqid(), 'external_status' => $st, 'tracking_number' => $tn,
                'customer_name' => 'B', 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000, 'total' => 1000,
                'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'not_shipped',
            ]);
            $o->user_id = $user->id;
            $o->save();
        };
        // 2 processed orders (one still without a resi), 1 not yet processed.
        $order('PROCESSED', 'SPX1');
        $order('PROCESSED', null);
        $order('READY_TO_SHIP', null);

        $booking = fn (string $st) => ShopeeBooking::create([
            'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'booking_sn' => uniqid(), 'booking_status' => $st,
        ]);
        $booking('PROCESSED');
        $booking('PROCESSED');
        $booking('READY_TO_SHIP');

        $res = $this->getJson('/api/dashboard')->assertOk();
        // 2 processed orders + 2 processed bookings = 4 (READY_TO_SHIP excluded).
        $this->assertSame(4, $res->json('actions.shopee_to_print'));
    }
}
