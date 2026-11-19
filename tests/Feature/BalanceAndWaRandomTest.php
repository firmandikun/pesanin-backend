<?php

namespace Tests\Feature;

use App\Models\CsStaff;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BalanceAndWaRandomTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $u, array $attrs): Order
    {
        $o = new Order(array_merge(['customer_name' => 'A', 'phone' => '0812', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'status' => 'processing'], $attrs));
        $o->forceFill(['user_id' => $u->id, 'code' => 'O'.uniqid(), 'paid_at' => now(), 'payment_status' => $attrs['payment_status'] ?? 'paid'])->save();

        return $o;
    }

    public function test_saldo_counts_only_money_held_by_pesanin(): void
    {
        $u = User::factory()->create();
        $this->order($u, ['payment_method' => 'epayment', 'payment_via' => 'platform', 'total' => 100000]);
        $this->order($u, ['payment_method' => 'epayment', 'payment_via' => 'platform', 'total' => 40000, 'status' => 'refunded']); // refunded
        $this->order($u, ['payment_method' => 'epayment', 'payment_via' => 'seller', 'total' => 70000]); // own Midtrans
        $this->order($u, ['payment_method' => 'bank_transfer', 'total' => 50000]); // straight to the seller
        $archived = $this->order($u, ['payment_method' => 'epayment', 'payment_via' => 'platform', 'total' => 25000]);
        $archived->forceFill(['archived_at' => now()])->save();

        $b = $this->actingAs($u)->getJson('/api/balance')->assertOk();
        $this->assertSame(125000, $b->json('held'));
        $this->assertSame(120000, $b->json('period.direct_received'));
        $this->assertSame(125000, $this->actingAs($u)->getJson('/api/balance/summary')->json('held'));
    }

    public function test_checkout_records_which_midtrans_took_the_payment(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-platform']);
        Http::fake(['*snap/v1/transactions' => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'])]);
        $u = User::factory()->create();
        $p = new Product(['name' => 'Ebook', 'slug' => 'ebook-via', 'type' => 'digital', 'price' => 50000, 'status' => 'active', 'payment_methods' => ['epayment']]);
        $p->user_id = $u->id;
        $p->save();
        $code = $this->postJson('/api/public/products/ebook-via/checkout', ['customer_name' => 'Ani', 'phone' => '081234567890', 'email' => 'ani@mail.com', 'quantity' => 1, 'payment_method' => 'epayment'])
            ->assertCreated()->json('order.code');
        $this->assertSame('platform', Order::where('code', $code)->value('payment_via'));
    }

    public function test_whatsapp_random_picks_a_cs_on_duty(): void
    {
        $u = User::factory()->create(['whatsapp' => '081111111111']);
        $u->followupTemplates()->create(['event' => 'redirect', 'name' => 'Redirect', 'message' => 'Halo, order {{order_id}}', 'is_active' => true]);
        $cs = new CsStaff(['name' => 'Rina', 'phone' => '082222222222', 'is_active' => true]);
        $cs->user_id = $u->id;
        $cs->save();
        $off = new CsStaff(['name' => 'Off', 'phone' => '083333333333', 'is_active' => false]);
        $off->user_id = $u->id;
        $off->save();
        $p = new Product(['name' => 'Ebook', 'slug' => 'ebook-wa', 'type' => 'digital', 'price' => 50000, 'status' => 'active', 'payment_methods' => ['bank_transfer'],
            'success_settings' => ['action' => 'whatsapp_random']]);
        $p->user_id = $u->id;
        $p->save();
        $r = $this->postJson('/api/public/products/ebook-wa/checkout', ['customer_name' => 'Ani', 'phone' => '081234567890', 'email' => 'ani@mail.com', 'quantity' => 1, 'payment_method' => 'bank_transfer'])->assertCreated();
        $this->assertSame('whatsapp', $r->json('action'));
        $this->assertStringContainsString('6282222222222', $r->json('whatsapp_url'));
    }
}
