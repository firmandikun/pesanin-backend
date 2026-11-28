<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\CodConfirm;
use App\Support\OrderAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Konfirmasi COD otomatis: WhatsApp "balas YA", parcels held until confirmed, replies and timeouts. */
class CodConfirmTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['graph.facebook.com/*/555/messages' => Http::response(['messages' => [['id' => 'wamid.C'.uniqid()]], 'contacts' => [['wa_id' => '6281234567890']]])]);
        $this->seller = User::factory()->create();
        UserIntegration::create(['user_id' => $this->seller->id, 'provider' => 'whatsapp_cloud', 'webhook_token' => 'tok', 'status' => 'active',
            'credentials' => ['access_token' => 'EAAT'], 'settings' => ['phone_number_id' => '555']]);
        $this->settings();
    }

    private function settings(array $extra = []): void
    {
        $this->seller->forceFill(['settings' => ['automation' => $extra + [
            'cod_confirm' => true, 'cod_confirm_template' => ['name' => 'konfirmasi_cod', 'language' => 'id', 'variables' => 4], 'cod_confirm_level' => 'all', 'cod_confirm_hours' => 12,
        ]]])->save();
    }

    private function order(string $phone = '081234567890', array $attrs = []): Order
    {
        $o = new Order($attrs + ['channel' => 'website', 'source' => 'checkout', 'code' => 'ORD-C'.(++$this->n), 'customer_name' => 'Ani', 'phone' => $phone,
            'address' => 'Jl. Melati No. 12 RT 03 RW 04, Bagelen', 'quantity' => 1, 'unit_price' => 150000, 'subtotal' => 150000, 'total' => 150000,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'pending', 'shipping_status' => 'not_shipped', 'courier' => 'JNE']);
        $o->user_id = $this->seller->id;
        $o->save();

        return $o;
    }

    private function inbound(string $text, string $from = '6281234567890', string $type = 'text'): void
    {
        $m = ['from' => $from, 'id' => 'wamid.in'.uniqid(), 'timestamp' => (string) time(), 'type' => $type];
        $m += $type === 'button' ? ['button' => ['text' => $text]] : ['text' => ['body' => $text]];
        $this->postJson('/api/whatsapp/webhook/tok', ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '555'], 'messages' => [$m]]]]]]])->assertOk();
    }

    public function test_sends_template_and_yes_reply_confirms(): void
    {
        $o = $this->order();
        OrderAutomation::run();

        $o->refresh();
        $this->assertSame('waiting', $o->cod_confirm_status);
        $this->assertTrue(CodConfirm::holds($o, true), 'held from auto resi while waiting');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/555/messages') && $r['template']['name'] === 'konfirmasi_cod'
            && collect($r['template']['components'][0]['parameters'])->pluck('text')->all() === ['Ani', $o->code, 'pesanan', 'Rp150.000']);

        $this->inbound('Ya, kirim', '6281234567890', 'button');
        $o->refresh();
        $this->assertSame('confirmed', $o->cod_confirm_status);
        $this->assertNotNull($o->cod_confirmed_at);
        $this->assertFalse(CodConfirm::holds($o, true));
    }

    public function test_cancel_reply_and_unrelated_text(): void
    {
        $o = $this->order();
        OrderAutomation::run();
        $this->inbound('kak ini warnanya apa aja?');
        $this->assertSame('waiting', $o->fresh()->cod_confirm_status, 'questions are left to the CS');
        $this->inbound('Batalkan pesanan');
        $this->assertSame('declined', $o->fresh()->cod_confirm_status);
        $this->assertTrue(AppNotification::where('title', 'Pembeli membatalkan pesanan COD')->exists());
    }

    public function test_no_reply_is_flagged_after_the_limit(): void
    {
        $o = $this->order();
        OrderAutomation::run();
        $this->travel(13)->hours();
        OrderAutomation::run();
        $this->assertSame('no_reply', $o->fresh()->cod_confirm_status);
        $this->assertTrue(AppNotification::where('title', 'Pembeli COD belum konfirmasi')->exists());
        $this->inbound('iya jadi kak');
        $this->assertSame('confirmed', $o->fresh()->cod_confirm_status, 'a late YES still counts');
    }

    public function test_only_risky_buyers_when_level_is_high_and_transfer_orders_untouched(): void
    {
        $this->settings(['cod_confirm_level' => 'high']);
        $low = $this->order();
        $transfer = $this->order('081299999999', ['payment_method' => 'bank_transfer']);
        OrderAutomation::run();
        $this->assertSame('skipped', $low->fresh()->cod_confirm_status);
        $this->assertNull($transfer->fresh()->cod_confirm_status);
        $this->assertFalse(CodConfirm::holds($low->fresh(), true));
        Http::assertNothingSent();
    }

    public function test_inactive_without_template_and_manual_override(): void
    {
        $this->settings(['cod_confirm_template' => null]);
        $this->assertFalse(CodConfirm::active($this->seller->fresh()));
        $o = $this->order();
        OrderAutomation::run();
        $this->assertNull($o->fresh()->cod_confirm_status);
        $this->assertFalse(CodConfirm::holds($o->fresh(), false));

        Sanctum::actingAs($this->seller);
        $this->putJson("/api/orders/{$o->id}/cod-confirm", ['status' => 'confirmed'])->assertOk()->assertJsonPath('cod_confirm_status', 'confirmed');
        $this->assertSame(1, count($this->getJson('/api/orders?cod_confirm=confirmed&from=2000-01-01')->json('orders.data')));
        $transfer = $this->order('081277777777', ['payment_method' => 'bank_transfer']);
        $this->putJson("/api/orders/{$transfer->id}/cod-confirm", ['status' => 'confirmed'])->assertStatus(422);
    }

    public function test_old_orders_are_not_held(): void
    {
        $o = $this->order();
        $o->forceFill(['created_at' => now()->subDays(5)])->save();
        $this->assertFalse(CodConfirm::holds($o->fresh(), true));
        $this->assertTrue(CodConfirm::holds($this->order('081211111111'), true), 'new and not asked yet: held');
    }
}
