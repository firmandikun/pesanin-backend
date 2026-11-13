<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Shopee\ShopeeBackfill;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Reports for an older period pull that range from Shopee instead of showing empty data. */
class ShopeeBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function shop(User $user): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active',
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30),
            'orders_synced_at' => now(), 'orders_backfilled_from' => now()->subDays(15)->toDateString()]);
        $shop->user_id = $user->id;
        $shop->save();

        return $shop;
    }

    public function test_backfill_pulls_only_the_uncovered_older_range(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        $asked = [];
        Http::fake(function ($req) use (&$asked) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_contains($req->url(), 'get_order_list')) {
                $asked[] = [(int) $q['time_from'], (int) $q['time_to']];
                $hit = (int) $q['time_from'] <= now()->subDays(40)->timestamp && (int) $q['time_to'] >= now()->subDays(40)->timestamp;

                return Http::response(['response' => ['order_list' => $hit ? [['order_sn' => 'OLD40']] : [], 'more' => false]]);
            }
            if (str_contains($req->url(), 'get_order_detail')) {
                return Http::response(['response' => ['order_list' => [['order_sn' => 'OLD40', 'order_status' => 'COMPLETED', 'item_list' => [], 'create_time' => now()->subDays(40)->timestamp]]]]);
            }

            return Http::response(['response' => []]);
        });

        $n = (new ShopeeSync)->backfill($shop, now()->subDays(60));
        $this->assertSame(1, $n);
        $this->assertSame('OLD40', Order::where('marketplace_shop_id', $shop->id)->value('external_order_sn'));
        // Never requested newer than the 15-day marker (already covered).
        $this->assertNotEmpty($asked);
        foreach ($asked as [$f]) {
            $this->assertLessThan(now()->subDays(14)->timestamp, $f);
        }
        // Marker moved back; a second call for the same range does nothing.
        $this->assertSame(now()->subDays(60)->startOfDay()->toDateString(), $shop->fresh()->orders_backfilled_from->toDateString());
        $this->assertSame(0, (new ShopeeSync)->backfill($shop->fresh(), now()->subDays(60)));
    }

    public function test_report_flags_backfilling_and_locks_against_duplicates(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $this->shop($user);

        // Range inside the covered window → no backfill.
        $this->assertFalse(ShopeeBackfill::ensure($user, now()->subDays(10)));
        // Older range → pending, and the lock blocks a second dispatch.
        $this->assertTrue(ShopeeBackfill::ensure($user, now()->subDays(90)));
        $this->assertTrue(ShopeeBackfill::ensure($user, now()->subDays(90)));

        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['response' => []])]);
        $this->getJson('/api/reports/profit?from='.now()->subDays(90)->toDateString().'&to='.today()->toDateString())
            ->assertOk()->assertJsonPath('backfilling', true);
    }
}
