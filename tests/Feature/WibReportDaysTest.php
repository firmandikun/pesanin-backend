<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Report days are WIB days. At 06:30 WIB it is still the previous day in UTC: "today" must not pull in yesterday's
 * ad spend, and an order placed at 06:00 WIB belongs to today, not yesterday.
 */
class WibReportDaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_today_at_dawn_counts_only_todays_wib_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 06:30', 'Asia/Jakarta'));
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'ads_ppn' => 0,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        foreach (['2026-11-14' => 200000, '2026-11-15' => 25477] as $day => $amount) {
            AdSpend::create(['user_id' => $user->id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $shop->id, 'spent_on' => $day,
                'amount' => $amount, 'synced' => true, 'campaign' => 'Shopee Ads (otomatis)']);
        }
        // 06:00 WIB on the 15th = 23:00 UTC on the 14th.
        $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => 'A',
            'external_status' => 'PROCESSED', 'customer_name' => 'B', 'quantity' => 1, 'unit_price' => 55879, 'subtotal' => 55879, 'total' => 55879,
            'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'not_shipped']);
        $o->user_id = $user->id;
        $o->created_at = Carbon::parse('2026-11-15 06:00', 'Asia/Jakarta')->utc();
        $o->save();

        $profit = $this->getJson('/api/reports/profit?from=2026-11-15&to=2026-11-15&channel=shopee&shop_id='.$shop->id)->assertOk()->json();
        $this->assertSame(25477, $profit['summary']['ads']);              // not 225477 (yesterday's spend)
        $this->assertSame(['2026-11-15'], array_column($profit['daily'], 'date'));
        $this->assertSame(25477, $profit['daily'][0]['ads']);
        $this->assertSame(['from' => '2026-11-15', 'to' => '2026-11-15'], array_intersect_key($profit, ['from' => 0, 'to' => 0]));

        $sales = $this->getJson('/api/reports/sales?from=2026-11-15&to=2026-11-15')->assertOk()->json();
        $this->assertSame([['2026-11-15', 1]], array_map(fn ($d) => [$d['date'], $d['orders']], $sales['daily']));

        $this->assertSame(1, $this->getJson('/api/dashboard')->assertOk()->json('today.orders'));
    }
}
