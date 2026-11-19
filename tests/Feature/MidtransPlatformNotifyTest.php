<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A seller without their own Midtrans is paid through Pesanin's platform account: its notification must mark the order paid. */
class MidtransPlatformNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_signed_notification_marks_order_paid(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-platform', 'services.midtrans.signature_key' => null]);
        $seller = User::factory()->create();
        $p = new Product(['name' => 'Ebook', 'slug' => 'ebook-mt', 'type' => 'digital', 'price' => 50000, 'status' => 'active']);
        $p->user_id = $seller->id;
        $p->save();
        $o = new Order(['code' => 'PSN-MT-1', 'customer_name' => 'Ani', 'phone' => '0812', 'quantity' => 1, 'unit_price' => 50000, 'subtotal' => 50000, 'total' => 50000,
            'payment_method' => 'epayment', 'payment_status' => 'unpaid', 'status' => 'pending']);
        $o->forceFill(['user_id' => $seller->id, 'product_id' => $p->id])->save();

        $n = ['order_id' => 'PSN-MT-1', 'status_code' => '200', 'gross_amount' => '50000.00', 'transaction_status' => 'settlement', 'fraud_status' => 'accept'];
        $this->postJson('/api/midtrans/notify', $n + ['signature_key' => hash('sha512', 'PSN-MT-1'.'200'.'50000.00'.'wrong-key')])->assertForbidden();
        $this->postJson('/api/midtrans/notify', $n + ['signature_key' => hash('sha512', 'PSN-MT-1'.'200'.'50000.00'.'SB-Mid-server-platform')])->assertOk();
        $this->assertSame('paid', $o->fresh()->payment_status);
    }
}
