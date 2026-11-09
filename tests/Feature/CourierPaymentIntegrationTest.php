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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Biteship + Midtrans with faked HTTP: checkout rates, COD filter, booking, webhook, payment notification. */
class CourierPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private Product $product;

    private Region $dest;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api.pesanin.test']);
        $this->seller = User::factory()->create(['store_name' => 'Toko Uji', 'whatsapp' => '081200000000']);
        $origin = Region::create(['province' => 'DKI JAKARTA', 'city' => 'JAKARTA SELATAN', 'district' => 'KEBAYORAN BARU', 'island' => 'JAWA']);
        $this->dest = Region::create(['province' => 'JAWA BARAT', 'city' => 'BANDUNG', 'district' => 'COBLONG', 'island' => 'JAWA']);
        $a = new Address(['label' => 'Gudang', 'name' => 'Gudang', 'phone' => '081200000000', 'address' => 'Jl. Uji 1', 'region_id' => $origin->id, 'postal_code' => '12110', 'is_default' => true]);
        $a->user_id = $this->seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner-uji', 'type' => 'physical', 'price' => 100000, 'weight' => 500, 'status' => 'active', 'payment_methods' => ['cod', 'epayment']]);
        $p->user_id = $this->seller->id;
        $p->save();
        $this->product = $p->fresh();
        foreach (['biteship' => ['api_key' => 'biteship_test.abc'], 'midtrans' => ['server_key' => 'SB-Mid-server-xyz', 'client_key' => 'SB-Mid-client-xyz']] as $prov => $creds) {
            UserIntegration::create(['user_id' => $this->seller->id, 'provider' => $prov, 'credentials' => $creds, 'webhook_token' => Str::random(40), 'status' => 'active']);
        }
        Http::fake([
            'api.biteship.com/v1/maps/areas*' => fn ($r) => Http::response(['success' => true, 'areas' => [[
                'id' => str_contains($r['input'], 'COBLONG') ? 'AREA_DEST' : 'AREA_ORIG',
                'administrative_division_level_2_name' => str_contains($r['input'], 'COBLONG') ? 'Bandung' : 'Jakarta Selatan',
                'administrative_division_level_3_name' => str_contains($r['input'], 'COBLONG') ? 'Coblong' : 'Kebayoran Baru',
            ]]]),
            'api.biteship.com/v1/rates/couriers' => Http::response(['success' => true, 'pricing' => [
                ['courier_name' => 'JNE', 'courier_code' => 'jne', 'courier_service_code' => 'reg', 'price' => 11000, 'shipment_duration_range' => '2 - 3', 'shipment_duration_unit' => 'days', 'available_for_cash_on_delivery' => true],
                ['courier_name' => 'J&T', 'courier_code' => 'jnt', 'courier_service_code' => 'ez', 'price' => 9000, 'shipment_duration_range' => '2 - 4', 'shipment_duration_unit' => 'days', 'available_for_cash_on_delivery' => false],
            ]]),
            'api.biteship.com/v1/orders' => Http::response(['success' => true, 'id' => 'BS_ORDER_1', 'status' => 'confirmed', 'courier' => ['waybill_id' => 'JNE0001234', 'company' => 'jne', 'type' => 'reg']]),
            'api.biteship.com/v1/orders/BS_ORDER_1' => Http::response(['success' => true, 'id' => 'BS_ORDER_1', 'status' => 'delivered', 'courier' => ['waybill_id' => 'JNE0001234']]),
            'app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token']),
        ]);
    }

    private function checkout(array $extra): array
    {
        return $this->postJson('/api/public/products/toner-uji/checkout', array_merge([
            'customer_name' => 'Budi', 'phone' => '081234567890', 'address' => 'Jl. Dago 5', 'region_id' => $this->dest->id, 'quantity' => 1,
        ], $extra))->assertCreated()->json();
    }

    public function test_checkout_uses_biteship_rates_and_cod_only_shows_cod_couriers(): void
    {
        $all = $this->postJson('/api/public/products/toner-uji/quote', ['region_id' => $this->dest->id, 'quantity' => 1, 'payment_method' => 'epayment'])->json('rates');
        $this->assertEqualsCanonicalizing(['jne:reg', 'jnt:ez'], array_column($all, 'code'));

        $cod = $this->postJson('/api/public/products/toner-uji/quote', ['region_id' => $this->dest->id, 'quantity' => 1, 'payment_method' => 'cod'])->json('rates');
        $this->assertSame(['jne:reg'], array_column($cod, 'code'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'rates/couriers') && $r['origin_area_id'] === 'AREA_ORIG' && $r['destination_area_id'] === 'AREA_DEST' && $r->header('Authorization')[0] === 'biteship_test.abc');
    }

    public function test_cod_order_is_booked_on_biteship_with_cod_amount_and_webhook_updates_it(): void
    {
        $this->checkout(['payment_method' => 'cod', 'courier' => 'jne:reg']);
        $order = Order::first();
        $this->assertSame('jne:reg', $order->courier_code);
        $this->assertSame(11000, (int) $order->shipping_cost);

        Sanctum::actingAs($this->seller);
        $res = $this->postJson('/api/orders/biteship-ship', ['order_ids' => [$order->id]])->assertOk()->json();
        $this->assertSame('JNE0001234', $res['shipped'][0]['waybill']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.biteship.com/v1/orders' && $r['destination_cash_on_delivery'] === (int) $order->total
            && $r['courier_company'] === 'jne' && $r['courier_type'] === 'reg' && $r['destination_area_id'] === 'AREA_DEST');

        $token = UserIntegration::where('provider', 'biteship')->value('webhook_token');
        $this->postJson("/api/biteship/webhook/{$token}", ['event' => 'order.status', 'order_id' => 'BS_ORDER_1', 'status' => 'delivered'])->assertOk();
        $order->refresh();
        $this->assertSame('delivered', $order->shipping_status);
        $this->assertSame('completed', $order->status);
        $this->postJson('/api/biteship/webhook/wrong-token', ['order_id' => 'BS_ORDER_1'])->assertOk(); // ignored
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

    public function test_auto_resi_books_paid_orders_and_cod_only_when_allowed(): void
    {
        $this->checkout(['payment_method' => 'cod', 'courier' => 'jne:reg']);
        $order = Order::first();
        $set = fn (array $a) => $this->seller->forceFill(['settings' => ['automation' => $a]])->save();

        $set(['auto_ship' => false]);
        $this->assertSame(0, \App\Support\OrderAutomation::autoShip());
        $set(['auto_ship' => true, 'auto_ship_cod' => false]);
        $this->assertSame(0, \App\Support\OrderAutomation::autoShip(), 'COD waits unless allowed');
        $set(['auto_ship' => true, 'auto_ship_cod' => true]);
        $this->assertSame(1, \App\Support\OrderAutomation::autoShip());
        $this->assertSame('JNE0001234', $order->fresh()->tracking_number);
        $this->assertSame(0, \App\Support\OrderAutomation::autoShip(), 'never booked twice');
        $this->assertTrue(\App\Models\AppNotification::where('title', 'Resi otomatis terbit')->exists());
    }

    public function test_auto_resi_failure_retries_then_stops(): void
    {
        $this->checkout(['payment_method' => 'cod', 'courier' => 'jne:reg']);
        $order = Order::first();
        $this->seller->forceFill(['settings' => ['automation' => ['auto_ship' => true, 'auto_ship_cod' => true]]])->save();
        $this->seller->addresses()->delete(); // no pickup address → Biteship booking cannot be made
        foreach (range(1, 4) as $_) {
            \App\Support\OrderAutomation::autoShip();
        }
        $order->refresh();
        $this->assertSame(3, $order->auto_ship_tries);
        $this->assertStringContainsString('pickup', $order->auto_ship_error);
        $this->assertTrue(\App\Models\AppNotification::where('title', 'Resi otomatis gagal')->exists());
    }
}
