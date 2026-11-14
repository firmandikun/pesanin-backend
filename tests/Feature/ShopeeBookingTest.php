<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\ShopeeBooking;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "Perlu Dikirim" in Seller Centre = orders + Pengiriman Kilat reservations + buyer cancel requests not yet answered. */
class ShopeeBookingTest extends TestCase
{
    use RefreshDatabase;

    private function shop(User $user): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();

        return $shop;
    }

    public function test_kilat_reservations_are_synced_and_listed_in_siap_diserahkan(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_contains($req->url(), 'get_booking_list')) {
                $list = ($q['booking_status'] ?? '') === 'PROCESSED' && ($q['time_to'] ?? 0) >= time() - 5 ? [['booking_sn' => '261112AAR7O6YVYR7JU']] : [];

                return Http::response(['response' => ['booking_list' => $list, 'more' => false]]);
            }
            if (str_contains($req->url(), 'get_booking_detail')) {
                return Http::response(['response' => ['booking_list' => [[
                    'booking_sn' => '261112AAR7O6YVYR7JU', 'booking_status' => 'PROCESSED', 'shipping_carrier' => 'SPX Hemat', 'create_time' => time(),
                    'item_list' => [['item_name' => 'Cover knalpot', 'model_name' => 'Airblade', 'model_sku' => 'CK-1', 'model_quantity' => 1]],
                ]]]]);
            }
            if (str_contains($req->url(), 'get_booking_tracking_number')) {
                return Http::response(['response' => ['tracking_number' => 'SPXID066696273523']]);
            }

            return Http::response(['response' => []]);
        });

        $this->assertSame(1, (new ShopeeSync)->bookings($shop));
        $this->assertSame('SPXID066696273523', ShopeeBooking::first()->tracking_number);

        $res = $this->actingAs($user)->getJson('/api/orders?channel=shopee&shopee_state=processed')->assertOk();
        $this->assertSame(1, $res->json('bookings.total'));
        $this->assertSame(0, $res->json('stats.total_orders')); // never counted as an order / revenue
        $this->assertSame(0, $this->getJson('/api/orders?channel=shopee&shopee_state=to_ship')->json('bookings.total'));
    }

    public function test_pending_cancel_request_stays_in_perlu_dikirim(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(['*' => Http::response(['response' => []])]);
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'A', 'order_status' => 'IN_CANCEL', 'item_list' => [], 'create_time' => time()])->forceFill(['tracking_number' => 'SPX1'])->save();
        $sync->upsertOrder($shop, ['order_sn' => 'B', 'order_status' => 'IN_CANCEL', 'item_list' => [], 'create_time' => time()])->forceFill(['tracking_number' => null])->save();
        $sync->upsertOrder($shop, ['order_sn' => 'C', 'order_status' => 'CANCELLED', 'item_list' => [], 'create_time' => time()]);

        $this->actingAs($user);
        $sns = fn ($state) => collect($this->getJson("/api/orders?channel=shopee&shopee_state={$state}")->json('orders.data'))->pluck('external_order_sn')->all();
        $this->assertSame(['A'], $sns('processed'));
        $this->assertSame(['B'], $sns('to_ship'));
        $this->assertSame(['C'], $sns('cancelled'));
    }

    public function test_kilat_reservation_awb_can_be_printed(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        $b = ShopeeBooking::create(['user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'booking_sn' => 'BK1', 'booking_status' => 'PROCESSED', 'shipping_carrier' => 'SPX Hemat', 'tracking_number' => 'SPXID1']);
        $sent = [];
        Http::fake(function ($req) use (&$sent) {
            $sent[] = parse_url($req->url(), PHP_URL_PATH);
            if (str_contains($req->url(), 'download_booking_shipping_document')) {
                return Http::response('%PDF-1.4 kilat', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response(['response' => ['result_list' => [['booking_sn' => 'BK1', 'status' => 'READY']]]]);
        });

        $res = $this->actingAs($user)->post('/api/labels/shopee/booking-pdf', ['booking_ids' => [$b->id]], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame('%PDF-1.4 kilat', $res->getContent());
        $this->assertContains('/api/v2/logistics/create_booking_shipping_document', $sent);

        // Not arranged yet → refused with a clear message.
        $b->update(['booking_status' => 'READY_TO_SHIP']);
        $this->postJson('/api/labels/shopee/booking-pdf', ['booking_ids' => [$b->id]])->assertStatus(422);
    }

    public function test_orders_from_kilat_stock_are_split_from_seller_stock(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(['*' => Http::response(['response' => []])]);
        $sync = new ShopeeSync;
        foreach (['A', 'B'] as $sn) {
            $sync->upsertOrder($shop, ['order_sn' => $sn, 'order_status' => 'SHIPPED', 'item_list' => [], 'create_time' => time()]);
        }
        ShopeeBooking::create(['user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'booking_sn' => 'BK', 'order_sn' => 'B', 'booking_status' => 'MATCHED']);

        $this->actingAs($user);
        $sns = fn ($stock) => collect($this->getJson("/api/orders?channel=shopee&shopee_state=shipping&stock={$stock}")->json('orders.data'))->pluck('external_order_sn')->all();
        $this->assertSame(['A'], $sns('seller'));
        $this->assertSame(['B'], $sns('kilat'));
        // A matched reservation is an order now, not a separate Kilat parcel.
        $this->assertSame(0, $this->getJson('/api/orders?channel=shopee&shopee_state=shipping')->json('bookings.total'));
    }

    public function test_return_requests_are_synced_and_counted_like_seller_centre(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(function ($req) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            if (str_contains($req->url(), 'get_return_list')) {
                $hit = ($q['create_time_to'] ?? 0) >= time() - 5 && ($q['page_no'] ?? '0') === '0';

                return Http::response(['response' => ['more' => false, 'return' => $hit ? [
                    ['return_sn' => 'R1', 'order_sn' => '261108RK0DQH22', 'status' => 'PROCESSING', 'reason' => 'WRONG_ITEM', 'refund_amount' => 19597, 'user' => ['username' => 'irfan'], 'create_time' => time(), 'return_seller_due_date' => time() + 86400, 'item' => [['name' => 'Cover', 'amount' => 1]]],
                    ['return_sn' => 'R2', 'order_sn' => 'X', 'status' => 'CLOSED', 'refund_amount' => 1000, 'create_time' => time() - 60],
                ] : []]]);
            }

            return Http::response(['response' => []]);
        });

        $this->assertSame(2, (new ShopeeSync)->returns($shop));
        $this->actingAs($user);
        $this->assertSame(1, $this->getJson('/api/orders?channel=shopee&facets=1&per_page=1')->json('attention.returns'));
        $this->assertSame('R1', $this->getJson('/api/marketplace/returns?filter=open')->json('data.0.return_sn'));
        $this->assertSame(2, $this->getJson('/api/marketplace/returns')->json('total'));
    }

    public function test_selesai_tab_shows_orders_completed_today_by_default(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(['*' => Http::response(['response' => []])]);
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'TODAY', 'order_status' => 'COMPLETED', 'item_list' => [], 'create_time' => now()->subDays(5)->timestamp, 'update_time' => now()->timestamp]);
        $sync->upsertOrder($shop, ['order_sn' => 'OLD', 'order_status' => 'COMPLETED', 'item_list' => [], 'create_time' => now()->subDays(40)->timestamp, 'update_time' => now()->subDays(30)->timestamp]);

        $this->actingAs($user);
        $sns = fn ($q) => collect($this->getJson("/api/orders?channel=shopee&shopee_state=done{$q}")->json('orders.data'))->pluck('external_order_sn')->sort()->values()->all();
        $this->assertSame(['TODAY'], $sns(''));          // default = completed today
        $this->assertSame(['OLD', 'TODAY'], $sns('&done=all')); // all completed
    }

    public function test_shop_list_reports_today_order_count(): void
    {
        $user = User::factory()->create();
        $shop = $this->shop($user);
        Http::fake(['*' => Http::response(['response' => []])]);
        $sync = new ShopeeSync;
        $sync->upsertOrder($shop, ['order_sn' => 'A', 'order_status' => 'COMPLETED', 'item_list' => [], 'create_time' => now()->timestamp]);
        $sync->upsertOrder($shop, ['order_sn' => 'B', 'order_status' => 'COMPLETED', 'item_list' => [], 'create_time' => now()->subDays(3)->timestamp]);

        $row = collect($this->actingAs($user)->getJson('/api/marketplace/shops')->json('shops'))->firstWhere('id', $shop->id);
        $this->assertSame(2, $row['orders_count']);
        $this->assertSame(1, $row['orders_today_count']);
    }
}
