<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bulk AWB print: an order Shopee already shipped is skipped (and moves to Dikirim) instead of failing the batch. */
class ShopeeAwbSkipShippedTest extends TestCase
{
    use RefreshDatabase;

    private function setUpShop(): array
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'is_demo' => false,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        $sync = new ShopeeSync;
        foreach (['KEEP', 'GONE'] as $sn) {
            $sync->upsertOrder($shop, ['order_sn' => $sn, 'order_status' => 'PROCESSED', 'shipping_carrier' => 'SPX', 'item_list' => [], 'create_time' => time()])
                ->forceFill(['tracking_number' => 'T'.$sn])->save();
        }

        return [$user, $shop];
    }

    private function fakeShopee(array $statuses): void
    {
        Http::fake(function ($req) use ($statuses) {
            $url = $req->url();
            if (str_contains($url, 'get_order_detail')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

                return Http::response(['response' => ['order_list' => array_map(fn ($sn) => [
                    'order_sn' => $sn, 'order_status' => $statuses[$sn], 'shipping_carrier' => 'SPX', 'item_list' => [], 'create_time' => time(),
                ], explode(',', $q['order_sn_list']))]]);
            }
            if (str_contains($url, 'download_shipping_document')) {
                return Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_contains($url, 'get_tracking_number')) {
                return Http::response(['response' => ['tracking_number' => 'TX']]);
            }

            return Http::response(['response' => ['result_list' => [['status' => 'READY']]]]);
        });
    }

    public function test_shipped_order_is_skipped_and_the_rest_prints(): void
    {
        [$user] = $this->setUpShop();
        $this->fakeShopee(['KEEP' => 'PROCESSED', 'GONE' => 'SHIPPED']);

        $res = $this->actingAs($user)->post('/api/labels/shopee/pdf', ['order_ids' => Order::pluck('id')->all()]);

        $res->assertOk();
        $this->assertSame('GONE', $res->headers->get('X-Awb-Skipped'));
        $this->assertSame('SHIPPED', Order::where('external_order_sn', 'GONE')->value('external_status'));
        $this->assertNotNull(Order::where('external_order_sn', 'KEEP')->value('awb_printed_at'));
        $this->assertNull(Order::where('external_order_sn', 'GONE')->value('awb_printed_at'));
    }

    public function test_all_shipped_explains_and_moves_them(): void
    {
        [$user] = $this->setUpShop();
        $this->fakeShopee(['KEEP' => 'SHIPPED', 'GONE' => 'SHIPPED']);

        $this->actingAs($user)->postJson('/api/labels/shopee/pdf', ['order_ids' => Order::pluck('id')->all()])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Semua pesanan terpilih sudah dalam pengiriman, jadi resinya tidak dicetak lagi (dipindah ke tab Dikirim): KEEP, GONE']);
        $this->assertSame(2, Order::where('external_status', 'SHIPPED')->count());
    }
}
