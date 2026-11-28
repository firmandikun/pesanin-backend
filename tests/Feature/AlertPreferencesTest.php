<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Alert;
use App\Support\MoneyCheck;
use App\Support\RestockForecast;
use App\Support\Shopee\ShipDeadline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Pengaturan → Notifikasi: each alert goes to the bell and the seller's Telegram bot, and can be switched off. */
class AlertPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    private function seller(array $notify = []): User
    {
        $user = User::factory()->create(['settings' => ['notify' => $notify]]);
        UserIntegration::create(['user_id' => $user->id, 'provider' => 'telegram', 'webhook_token' => Str::random(40), 'status' => 'active',
            'credentials' => ['bot_token' => '123:abc'], 'settings' => ['bot_username' => 'tokobot', 'chat_id' => '999', 'chat_name' => 'Toko']]);
        $this->sent = [];
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'api.telegram.org')) {
                $this->sent[] = $req->data()['text'] ?? '';

                return Http::response(['ok' => true, 'result' => []]);
            }

            return Http::response([]);
        });

        return $user;
    }

    private function lateOrder(User $user): void
    {
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 7, 'shop_name' => 'Toko A', 'status' => 'active']);
        $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => 'LATE1',
            'external_status' => 'READY_TO_SHIP', 'customer_name' => 'B', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1,
            'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'not_shipped',
            'custom_fields' => ['ship_by' => now()->subHour()->toIso8601String()]]);
        $o->user_id = $user->id;
        $o->save();
    }

    public function test_alert_goes_to_bell_and_telegram(): void
    {
        $user = $this->seller();
        Alert::send($user, 'test', 'Judul', "Baris 1\nBaris 2", '/dashboard', '📊', 'Detail: Dashboard');

        $this->assertSame(1, AppNotification::where('user_id', $user->id)->count());
        $this->assertSame(["📊 Judul\n\nBaris 1\nBaris 2\n\nDetail: Dashboard"], $this->sent);
    }

    public function test_ship_deadline_alert_respects_the_switch(): void
    {
        $user = $this->seller(['ship_deadline' => false]);
        $this->lateOrder($user);
        $this->assertSame(0, ShipDeadline::notify($user));
        $this->assertSame([], $this->sent);

        $user->update(['settings' => ['notify' => ['ship_deadline' => true]]]);
        $this->assertSame(1, ShipDeadline::notify($user->fresh()));
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('⏰ 1 pesanan lewat batas kirim Shopee', $this->sent[0]);
    }

    public function test_restock_and_money_check_switches(): void
    {
        $user = $this->seller(['restock' => false, 'money_check' => false]);
        $this->assertSame(0, RestockForecast::notify($user));
        $this->assertSame(0, MoneyCheck::notify($user));
        $this->assertFalse(Alert::enabled($user, 'restock'));
        $this->assertTrue(Alert::enabled($user, 'daily_summary'), 'on by default');
    }

    public function test_telegram_test_message_lists_what_will_arrive(): void
    {
        $user = $this->seller();
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/telegram/test')->assertOk();
        $this->assertStringContainsString('ringkasan laba harian', $this->sent[0]);
    }
}
