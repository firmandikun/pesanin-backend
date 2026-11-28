<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderHours;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Peta Jam Ramai: orders per weekday × hour in WIB. */
class OrderHoursTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    private int $sn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-28 12:00', 'Asia/Jakarta')); // Saturday
        $this->user = User::factory()->create();
        $this->shop = MarketplaceShop::create(['user_id' => $this->user->id, 'platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'Toko A', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(string $wib, string $channel = 'shopee', string $status = 'completed', int $total = 50000): void
    {
        $o = new Order(['channel' => $channel, 'source' => $channel === 'shopee' ? 'marketplace' : 'web', 'marketplace_shop_id' => $channel === 'shopee' ? $this->shop->id : null,
            'external_order_sn' => 'SN'.(++$this->sn), 'customer_name' => 'B', 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total, 'total' => $total,
            'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => $status, 'shipping_status' => 'delivered']);
        $o->user_id = $this->user->id;
        $o->created_at = Carbon::parse($wib, 'Asia/Jakarta')->utc();
        $o->save();
    }

    public function test_buckets_orders_by_wib_day_and_hour(): void
    {
        $this->order('2026-11-23 20:15');            // Monday 20.00 WIB (13.15 UTC)
        $this->order('2026-11-23 20:50');
        $this->order('2026-11-27 00:30');            // Friday 00.00 WIB (Thursday in UTC)
        $this->order('2026-11-26 09:00', 'web');     // Thursday; web order: only with channel=all
        $this->order('2026-11-25 10:00', 'shopee', 'cancelled');
        $this->order('2026-10-01 20:00');            // older than 30 days

        Sanctum::actingAs($this->user);
        $r = $this->getJson('/api/marketplace/listings/order-hours')->assertOk()->json();
        $this->assertSame(2, $r['grid'][0][20]['orders']);
        $this->assertSame(100000, $r['grid'][0][20]['revenue']);
        $this->assertSame(1, $r['grid'][4][0]['orders']);
        $this->assertSame(['orders' => 3, 'revenue' => 150000, 'days' => 30, 'max_cell' => 2], $r['summary']);
        $this->assertSame([2, 0, 0, 0, 1, 0, 0], $r['by_day']);
        $this->assertSame([], $r['tips'], 'too few orders for advice');

        $all = $this->getJson('/api/marketplace/listings/order-hours?channel=all&days=90')->assertOk()->json();
        $this->assertSame(5, $all['summary']['orders']);
        $this->assertSame(1, $all['grid'][3][9]['orders']);
    }

    public function test_tips_name_the_peak_window(): void
    {
        foreach (range(1, 15) as $i) {
            $this->order('2026-11-2'.(1 + $i % 7).' 19:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        foreach (range(1, 10) as $i) {
            $this->order('2026-11-24 12:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $r = OrderHours::compute($this->user);
        $this->assertStringContainsString('Jam paling ramai: 18.00–21.00 WIB (60% pesanan)', $r['tips'][0]);
        $this->assertStringContainsString('sekitar 17.00 WIB', $r['tips'][1]);
        $this->assertStringContainsString('Pesanan hampir tidak ada jam 00.00–12.00, 13.00–19.00, 20.00–00.00', $r['tips'][3]);
        $this->assertSame([17, 15], OrderHours::peakWindow(array_replace(array_fill(0, 24, 0), [17 => 5, 18 => 5, 19 => 5])));
        $this->assertSame([23, 9], OrderHours::peakWindow(array_replace(array_fill(0, 24, 0), [23 => 3, 0 => 3, 1 => 3])), 'wraps past midnight');
    }
}
