<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\AppNotification;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\DailySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ringkasan laba harian: yesterday (WIB) vs the day before, sent once per day, switchable off. */
class DailySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(User $user, MarketplaceShop $shop, string $sn, int $total, string $wib): void
    {
        $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => $sn,
            'external_status' => 'COMPLETED', 'customer_name' => 'B', 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total, 'total' => $total,
            'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'completed', 'shipping_status' => 'delivered']);
        $o->user_id = $user->id;
        $o->created_at = Carbon::parse($wib, 'Asia/Jakarta')->utc();
        $o->save();
    }

    private function seller(): User
    {
        // 07:00 WIB on the 28th: "yesterday" is the 27th.
        Carbon::setTestNow(Carbon::parse('2026-11-28 07:00', 'Asia/Jakarta'));
        $user = User::factory()->create();
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 5, 'shop_name' => 'Toko A', 'status' => 'active']);
        $this->order($user, $shop, 'A', 100000, '2026-11-27 23:30');   // yesterday, late evening WIB (= 16:30 UTC)
        $this->order($user, $shop, 'B', 50000, '2026-11-26 10:00');    // the day before
        $this->order($user, $shop, 'C', 999000, '2026-11-28 06:00');   // today: not in the summary
        AdSpend::create(['user_id' => $user->id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $shop->id, 'spent_on' => '2026-11-27', 'amount' => 20000, 'synced' => true]);

        return $user;
    }

    public function test_builds_yesterday_in_wib_and_compares_with_the_day_before(): void
    {
        $user = $this->seller();
        $d = DailySummary::build($user);

        $this->assertSame('2026-11-27', $d['date']);
        $this->assertSame([1, 100000, 50000, 22200], [$d['orders'], $d['revenue'], $d['prev_revenue'], $d['ads']]);

        [$title, $body] = DailySummary::text($d);
        $this->assertStringContainsString('Kemarin (27 Nov): laba bersih', $title);
        $this->assertStringContainsString('dari 1 pesanan', $title);
        $this->assertStringContainsString('Omset Rp100.000 (▲100%)', $body);
        $this->assertStringContainsString('Biaya iklan Rp22.200', $body);

        // What-to-check lines, from the alert counts.
        [, $body] = DailySummary::text(['ship_late' => 1, 'ship_today' => 9, 'ads_losing' => 3, 'ads_loss' => 84000, 'ads_no_sales' => 0, 'ads_wasted' => 0,
            'stock_out' => 2, 'stock_critical' => 5, 'missing_cost_orders' => 32] + $d);
        $this->assertStringContainsString("Perlu dicek hari ini:\n• 1 pesanan Shopee TERLAMBAT dikirim\n• 9 pesanan Shopee harus dikirim dalam 24 jam\n• 3 iklan merugi (±Rp84.000)\n• 2 SKU habis · 5 SKU hampir habis\n• 32 pesanan belum ada HPP", $body);
    }

    public function test_sends_once_per_day_and_respects_the_switch(): void
    {
        $user = $this->seller();

        $this->artisan('report:daily-summary')->expectsOutputToContain('Ringkasan harian terkirim: 1')->assertSuccessful();
        $n = AppNotification::where('user_id', $user->id)->where('type', 'summary')->sole();
        $this->assertSame('/dashboard', $n->url);
        $this->assertFalse(DailySummary::send($user), 'only once per day');

        $user->update(['settings' => ['notify' => ['daily_summary' => false]]]);
        Carbon::setTestNow(Carbon::parse('2026-11-29 07:00', 'Asia/Jakarta'));
        $this->assertFalse(DailySummary::send($user->fresh()), 'switched off');
    }

    public function test_quiet_seller_gets_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-28 07:00', 'Asia/Jakarta'));
        $user = User::factory()->create();
        MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 6, 'shop_name' => 'Sepi', 'status' => 'active']);

        $this->assertTrue(DailySummary::isEmpty(DailySummary::build($user)));
        $this->assertFalse(DailySummary::send($user));
        $this->assertSame(0, AppNotification::where('user_id', $user->id)->count());
    }

    public function test_switch_is_saved_through_user_settings(): void
    {
        $user = User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->putJson('/api/user-settings', ['key' => 'notify', 'value' => ['daily_summary' => false]])->assertOk();
        $this->assertFalse(DailySummary::enabled($user->fresh()));
    }
}
