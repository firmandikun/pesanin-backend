<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\AwbSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Scheduled Shopee AWB PDF to the seller's Telegram. */
class AwbScheduleTest extends TestCase
{
    use RefreshDatabase;

    private array $telegram = [];

    private function seller(array $automation = ['awb_schedule' => true, 'awb_times' => ['08:00']]): User
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k', 'services.node.binary' => '/nonexistent/node']);
        $user = User::factory()->create(['settings' => ['automation' => $automation]]);
        UserIntegration::create(['user_id' => $user->id, 'provider' => 'telegram', 'webhook_token' => Str::random(40), 'status' => 'active',
            'credentials' => ['bot_token' => '123:abc'], 'settings' => ['bot_username' => 'tokobot', 'chat_id' => '999', 'chat_name' => 'Firman']]);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'is_demo' => false,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        foreach ([['A1', 'PROCESSED', 'SPX1', null], ['A2', 'PROCESSED', 'SPX2', now()], ['A3', 'READY_TO_SHIP', null, null]] as [$sn, $st, $tn, $printed]) {
            $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => $sn,
                'external_status' => $st, 'tracking_number' => $tn, 'courier' => 'SPX', 'awb_printed_at' => $printed, 'customer_name' => 'B',
                'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1, 'payment_method' => 'marketplace', 'payment_status' => 'paid',
                'status' => 'processing', 'shipping_status' => 'not_shipped']);
            $o->user_id = $user->id;
            $o->save();
        }
        $this->telegram = [];
        Http::fake(function ($req) {
            $url = $req->url();
            if (str_contains($url, 'api.telegram.org')) {
                $this->telegram[] = str_contains($url, 'sendDocument') ? 'document' : 'message';

                return Http::response(['ok' => true, 'result' => []]);
            }
            if (str_contains($url, 'download_shipping_document')) {
                return Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response(['response' => ['result_list' => [['status' => 'READY']]]]);
        });

        return $user;
    }

    public function test_due_slot_sends_unprinted_labels_once(): void
    {
        $this->seller();
        $at = Carbon::parse('2026-11-15 08:05', 'Asia/Jakarta');

        $this->assertSame(1, AwbSchedule::run($at));
        $this->assertSame(['document'], $this->telegram);
        // Only the ready, not-yet-printed order was sent, and it is now marked printed.
        $this->assertNotNull(Order::where('external_order_sn', 'A1')->value('awb_printed_at'));
        $this->assertNull(Order::where('external_order_sn', 'A3')->value('awb_printed_at'));

        // Same slot again: nothing more.
        $this->assertSame(0, AwbSchedule::run($at->copy()->addMinute()));
        $this->assertSame(['document'], $this->telegram);
    }

    public function test_outside_slot_or_disabled_does_nothing(): void
    {
        $user = $this->seller();
        $this->assertSame(0, AwbSchedule::run(Carbon::parse('2026-11-15 07:30', 'Asia/Jakarta')));
        $this->assertSame([], $this->telegram);

        $user->update(['settings' => ['automation' => ['awb_schedule' => false, 'awb_times' => ['08:00']]]]);
        $this->assertSame(0, AwbSchedule::run(Carbon::parse('2026-11-15 08:01', 'Asia/Jakarta')));
        $this->assertSame([], $this->telegram);
    }

    public function test_nothing_to_print_sends_a_short_note(): void
    {
        $user = $this->seller();
        Order::where('user_id', $user->id)->update(['awb_printed_at' => now()]);

        AwbSchedule::run(Carbon::parse('2026-11-15 08:00', 'Asia/Jakarta'));
        $this->assertSame(['message'], $this->telegram);
    }

    public function test_times_are_validated(): void
    {
        $this->assertSame(['08:00', '13:30'], AwbSchedule::times(['13:30', 'x', '25:00', '08:00', '08:00']));
    }
}
