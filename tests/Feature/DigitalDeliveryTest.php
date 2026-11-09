<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\OrderAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Digital products are delivered automatically once paid: access page, WhatsApp template, email. */
class DigitalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.frontend_url' => 'http://front', 'services.meta.graph_version' => 'v27.0']);
        $this->seller = User::factory()->create(['store_name' => 'Toko Ebook']);
        $p = new Product(['name' => 'Ebook Jualan', 'slug' => 'ebook', 'type' => 'digital', 'price' => 50000, 'status' => 'active', 'payment_methods' => ['bank_transfer'],
            'digital_content' => ['url' => 'https://drive.example.com/ebook', 'instructions' => 'Unduh lalu simpan.']]);
        $p->user_id = $this->seller->id;
        $p->save();
        $o = new Order(['code' => 'FKDIGI1', 'product_id' => $p->id, 'customer_name' => 'Rina', 'phone' => '081234567890', 'email' => 'rina@example.com',
            'total' => 50000, 'subtotal' => 50000, 'payment_method' => 'bank_transfer', 'status' => 'pending']);
        $o->user_id = $this->seller->id;
        $o->save();
        $this->order = $o;
    }

    public function test_paid_digital_order_is_delivered_by_email_and_completed(): void
    {
        Mail::fake();
        $this->assertSame(0, OrderAutomation::deliverDigital(), 'unpaid orders wait');

        Sanctum::actingAs($this->seller);
        $this->putJson("/api/orders/{$this->order->id}", ['payment_status' => 'paid'])->assertOk();
        $this->assertSame(1, OrderAutomation::deliverDigital());
        $this->assertSame(0, OrderAutomation::deliverDigital(), 'sent once');

        $o = $this->order->fresh();
        $this->assertNotNull($o->digital_sent_at);
        $this->assertSame('completed', $o->status);
        $this->assertNotNull($o->digital_token);

        $this->getJson("/api/public/digital/{$o->digital_token}")->assertOk()
            ->assertJsonPath('paid', true)->assertJsonPath('content.url', 'https://drive.example.com/ebook')->assertJsonPath('product', 'Ebook Jualan');
        $this->getJson('/api/public/digital/nope')->assertNotFound();
    }

    public function test_access_page_hides_content_until_paid_and_payment_page_links_it(): void
    {
        $url = OrderAutomation::accessUrl($this->order);
        $this->assertStringStartsWith('http://front/d/', $url);
        $this->getJson('/api/public/digital/'.$this->order->fresh()->digital_token)->assertJsonPath('paid', false)->assertJsonPath('content', null);

        $this->order->update(['payment_method' => 'epayment', 'payment_status' => 'paid']);
        $this->getJson('/api/public/orders/FKDIGI1/payment')->assertJsonPath('access_url', $url);
    }

    public function test_whatsapp_template_and_manual_resend(): void
    {
        Mail::fake();
        Http::fake(['graph.facebook.com/v27.0/555/messages' => Http::response(['messages' => [['id' => 'wamid.D']], 'contacts' => [['wa_id' => '6281234567890']]])]);
        UserIntegration::create(['user_id' => $this->seller->id, 'provider' => 'whatsapp_cloud', 'webhook_token' => 'tok', 'status' => 'active',
            'credentials' => ['access_token' => 'EAAT'], 'settings' => ['phone_number_id' => '555']]);
        $this->seller->forceFill(['settings' => ['automation' => ['digital_wa_template' => ['name' => 'akses_produk', 'language' => 'id', 'variables' => 2], 'digital_email' => false]]])->save();

        Sanctum::actingAs($this->seller);
        $this->postJson("/api/orders/{$this->order->id}/digital-send")->assertStatus(422); // not paid yet
        $this->order->update(['payment_status' => 'paid']);
        $r = $this->postJson("/api/orders/{$this->order->id}/digital-send")->assertOk();
        $r->assertJsonPath('sent', ['WhatsApp']);
        Http::assertSent(fn (HttpRequest $q) => $q['to'] === '6281234567890' && $q['template']['name'] === 'akses_produk'
            && $q['template']['components'][0]['parameters'][0]['text'] === 'Rina' && str_starts_with($q['template']['components'][0]['parameters'][1]['text'], 'http://front/d/'));
        Mail::assertNothingOutgoing();
    }

    public function test_private_file_upload_and_download_only_when_paid(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        Sanctum::actingAs($this->seller);
        $up = $this->post('/api/uploads/digital', ['file' => \Illuminate\Http\UploadedFile::fake()->create('panduan.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertStringStartsWith("digital/{$this->seller->id}/", $up['file']);
        $p = $this->order->product;
        $p->update(['digital_content' => ['file' => $up['file'], 'file_name' => 'panduan.pdf']]);

        $token = basename(OrderAutomation::accessUrl($this->order));
        $this->get("/api/public/digital/{$token}/file")->assertNotFound(); // unpaid
        $this->order->update(['payment_status' => 'paid']);
        $this->get("/api/public/digital/{$token}/file")->assertOk()->assertDownload('panduan.pdf');
        $this->getJson("/api/public/digital/{$token}")->assertJsonPath('content.has_file', true);
    }

    public function test_old_paid_orders_are_not_delivered_retroactively(): void
    {
        Mail::fake();
        $this->order->forceFill(['payment_status' => 'paid', 'paid_at' => now()->subDays(10)])->save();
        $this->assertSame(0, OrderAutomation::deliverDigital());
        $this->order->forceFill(['paid_at' => now()])->save();
        $this->assertSame(1, OrderAutomation::deliverDigital());
    }
}
