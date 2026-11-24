<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** RajaOngkir + Midtrans with faked HTTP: checkout rates (also for COD), manual resi stays possible, payment notification. */
class CourierPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Region $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.pesanin.test']);
        $this->seller = User::factory()->create(['store_name' => 'Toko Uji', 'whatsapp' => '081200000000']);
        $origin = Region::create(['province' => 'DKI JAKARTA', 'city' => 'JAKARTA SELATAN', 'district' => 'KEBAYORAN BARU', 'island' => 'JAWA', 'rajaongkir_id' => 100]);
        $this->dest = Region::create(['province' => 'JAWA BARAT', 'city' => 'BANDUNG', 'district' => 'COBLONG', 'island' => 'JAWA', 'rajaongkir_id' => 200]);
        $a = new Address(['label' => 'Gudang', 'name' => 'Gudang', 'phone' => '081200000000', 'address' => 'Jl. Uji 1', 'region_id' => $origin->id, 'postal_code' => '12110', 'is_default' => true]);
        $a->user_id = $this->seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner-uji', 'type' => 'physical', 'price' => 100000, 'weight' => 500, 'status' => 'active', 'payment_methods' => ['cod', 'epayment']]);
        $p->user_id = $this->seller->id;
        $p->save();
        // KiriminAja and Midtrans run on Pesanin's own keys from .env.
        config(['services.kiriminaja.key' => 'v4.local.ka-key', 'services.kiriminaja.sandbox' => true, 'services.midtrans.server_key' => 'SB-Mid-server-xyz', 'services.midtrans.signature_key' => null, 'services.midtrans.production' => false]);
        Http::fake([
            'tdev.kiriminaja.com/api/mitra/v6.1/addresses*' => fn ($r) => Http::response([
                'status' => true,
                'data' => [
                    str_contains(strtoupper((string) ($r['search'] ?? '')), 'KEBAYORAN')
                        ? ['district_id' => 100, 'district_name' => 'KEBAYORAN BARU', 'city_name' => 'JAKARTA SELATAN']
                        : ['district_id' => 200, 'district_name' => 'COBLONG', 'city_name' => 'BANDUNG'],
                ],
            ]),
            'tdev.kiriminaja.com/api/mitra/v6.1/shipping_price' => Http::response([
                'status' => true,
                'results' => [
                    ['courier' => 'jne', 'courier_name' => 'JNE', 'service_type' => 'REG', 'cost' => 11000, 'etd' => '2-3'],
                    ['courier' => 'jnt', 'courier_name' => 'J&T', 'service_type' => 'EZ', 'cost' => 9000, 'etd' => '2-4'],
                ],
            ]),
            'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token']),
        ]);
    }

    private function checkout(array $extra): array
    {
        return $this->postJson('/api/public/products/toner-uji/checkout', array_merge([
            'customer_name' => 'Budi', 'phone' => '081234567890', 'address' => 'Jl. Dago 5', 'region_id' => $this->dest->id, 'quantity' => 1,
        ], $extra))->assertCreated()->json();
    }

    public function test_checkout_uses_kiriminaja_rates_for_every_payment_method(): void
    {
        foreach (['epayment', 'cod'] as $method) {
            $rates = $this->postJson('/api/public/products/toner-uji/quote', ['region_id' => $this->dest->id, 'quantity' => 1, 'payment_method' => $method])->json('rates');
            $this->assertEqualsCanonicalizing(['jne:reg', 'jnt:ez'], array_column($rates, 'code'));
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), 'shipping_price') && $r['origin'] == 100 && $r['destination'] == 200 && $r->header('Authorization')[0] === 'Bearer v4.local.ka-key');

        $this->checkout(['payment_method' => 'cod', 'courier' => 'jne:reg']);
        $order = Order::first();
        $this->assertSame(['jne:reg', 11000], [$order->courier_code, (int) $order->shipping_cost]);
        $this->assertNull($order->shipping_ref); // no booking: the seller enters the waybill after handing the parcel over
    }

    public function test_epayment_creates_snap_and_signed_notification_marks_paid(): void
    {
        $res = $this->checkout(['payment_method' => 'epayment', 'payment_channel' => 'qris', 'courier' => 'jnt:ez']);
        $this->assertStringContainsString('snap/v4/redirection', $res['payment_url']);
        $order = Order::first();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'snap/v1/transactions') && $r['transaction_details']['order_id'] === $order->code
            && in_array('other_qris', $r['enabled_payments'], true) && $r->header('X-Override-Notification')[0] === 'https://api.pesanin.test/api/midtrans/notify');

        $n = ['order_id' => $order->code, 'status_code' => '200', 'gross_amount' => number_format($order->total, 2, '.', ''), 'transaction_status' => 'settlement', 'payment_type' => 'qris', 'transaction_id' => 'trx-1'];
        $this->postJson('/api/midtrans/notify', $n + ['signature_key' => 'forged'])->assertForbidden();
        $this->postJson('/api/midtrans/notify', $n + ['signature_key' => hash('sha512', $n['order_id'].'200'.$n['gross_amount'].'SB-Mid-server-xyz')])->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('processing', $order->status);
        $this->getJson("/api/public/orders/{$order->code}/payment")->assertJson(['payment_status' => 'paid', 'payment_url' => null]);
    }
}
