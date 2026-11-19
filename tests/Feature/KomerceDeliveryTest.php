<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Komerce Delivery (faked per rajaongkir.com/docs/delivery-order-api): store order → pickup → waybill on the order. */
class KomerceDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private function setUpShop(bool $delivery = true): void
    {
        $this->seller = User::factory()->create(['store_name' => 'Toko Uji', 'whatsapp' => '081200000000', 'email' => 'toko@uji.test']);
        $origin = Region::create(['province' => 'DKI JAKARTA', 'city' => 'JAKARTA SELATAN', 'district' => 'KEBAYORAN BARU', 'island' => 'Jawa', 'postal_code' => '12110', 'rajaongkir_id' => 100]);
        $dest = Region::create(['province' => 'JAWA BARAT', 'city' => 'BANDUNG', 'district' => 'COBLONG', 'island' => 'Jawa', 'postal_code' => '40132', 'rajaongkir_id' => 200]);
        $a = new Address(['label' => 'Gudang', 'name' => 'Gudang Uji', 'phone' => '+6281200000000', 'address' => 'Jl. Uji 1', 'region_id' => $origin->id, 'is_default' => true]);
        $a->user_id = $this->seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner-k', 'type' => 'physical', 'price' => 100000, 'weight' => 500, 'status' => 'active', 'payment_methods' => ['cod', 'bank_transfer']]);
        $p->user_id = $this->seller->id;
        $p->save();
        config(['services.rajaongkir.key' => 'ship-key', 'services.rajaongkir.delivery_key' => 'deliv-key', 'services.rajaongkir.delivery' => $delivery, 'services.rajaongkir.sandbox' => true, 'services.rajaongkir.vehicle' => 'Motor']);
        Http::fake([
            'rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response(['meta' => ['status' => 'success', 'code' => 200], 'data' => [
                ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'cost' => 11000, 'etd' => '2-3 day'],
            ]]),
            'api-sandbox.collaborator.komerce.id/tariff/api/v1/destination/search*' => fn (HttpRequest $r) => Http::response(['meta' => ['status' => 'success', 'code' => 200], 'data' => [
                $r['keyword'] === '12110'
                    ? ['id' => 31001, 'label' => 'x', 'subdistrict_name' => 'SELONG', 'district_name' => 'KEBAYORAN BARU', 'city_name' => 'JAKARTA SELATAN', 'zip_code' => '12110']
                    : ['id' => 42002, 'label' => 'y', 'subdistrict_name' => 'DAGO', 'district_name' => 'COBLONG', 'city_name' => 'KOTA BANDUNG', 'zip_code' => '40132'],
            ]]),
            'api-sandbox.collaborator.komerce.id/order/api/v1/orders/store' => Http::response(['meta' => ['message' => 'Success Create New Order', 'code' => 201, 'status' => 'success'], 'data' => ['order_id' => 9, 'order_no' => 'KOM123']], 201),
            'api-sandbox.collaborator.komerce.id/order/api/v1/pickup/request' => Http::response(['meta' => ['code' => 201, 'status' => 'success'], 'data' => [['status' => 'success', 'order_no' => 'KOM123', 'awb' => 'JNE999']]], 201),
        ]);
        $this->postJson('/api/public/products/toner-k/checkout', ['customer_name' => 'Budi Santoso', 'phone' => '081234567890', 'address' => 'Jl. Dago 5', 'region_id' => $dest->id,
            'quantity' => 2, 'payment_method' => 'cod', 'courier' => 'jne:reg'])->assertCreated();
    }

    public function test_pesan_kurir_books_order_and_saves_waybill(): void
    {
        $this->setUpShop();
        $order = Order::first();
        $r = $this->actingAs($this->seller)->postJson('/api/orders/book', ['order_ids' => [$order->id]])->assertOk();
        $this->assertSame([['code' => $order->code, 'waybill' => 'JNE999']], $r->json('shipped'));
        $order->refresh();
        $this->assertSame(['KOM123', 'komship', 'JNE999'], [$order->shipping_ref, $order->shipping_provider, $order->tracking_number]);

        $store = Http::recorded()->map(fn ($p) => $p[0])->first(fn ($r) => str_ends_with($r->url(), '/orders/store'));
        $this->assertSame('deliv-key', $store->header('x-api-key')[0]); // Shipping Delivery key, not the Shipping Cost one
        $this->assertSame([31001, 42002, 'JNE', 'REG', 'COD', 'Toko Uji', '081200000000', '081234567890'], [$store['shipper_destination_id'], $store['receiver_destination_id'],
            $store['shipping'], $store['shipping_type'], $store['payment_method'], $store['brand_name'], $store['shipper_phone'], $store['receiver_phone']]);
        $this->assertSame((int) $order->subtotal + 11000, $store['grand_total']);
        $this->assertSame($store['grand_total'], $store['cod_value']);
        $this->assertSame(2, $store['order_details'][0]['qty']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pickup/request') && $r['orders'] === [['order_no' => 'KOM123']] && $r['pickup_vehicle'] === 'Motor');

        $this->actingAs($this->seller)->postJson('/api/orders/book', ['order_ids' => [$order->id]])->assertJsonPath('failed.0.error', 'sudah punya resi');
    }

    public function test_auto_resi_runs_for_paid_or_allowed_cod_orders(): void
    {
        $this->setUpShop();
        $set = fn (array $a) => $this->seller->forceFill(['settings' => ['automation' => $a]])->save();
        $set(['auto_ship' => true, 'auto_ship_cod' => false]);
        $this->assertSame(0, \App\Support\RajaOngkir\CourierBooking::auto(), 'COD waits unless allowed');
        $set(['auto_ship' => true, 'auto_ship_cod' => true]);
        $this->assertSame(1, \App\Support\RajaOngkir\CourierBooking::auto());
        $this->assertSame('JNE999', Order::first()->tracking_number);
        $this->assertSame(0, \App\Support\RajaOngkir\CourierBooking::auto(), 'never booked twice');
    }

    public function test_booking_needs_delivery_switched_on(): void
    {
        $this->setUpShop(false);
        $this->actingAs($this->seller)->postJson('/api/orders/book', ['order_ids' => [Order::first()->id]])->assertStatus(422);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'collaborator.komerce.id'));
    }

    public function test_env_keys_are_shown_as_status_only_and_seller_keys_are_ignored(): void
    {
        $this->setUpShop();
        // An old per-seller key is ignored: everything runs on Pesanin's .env key.
        UserIntegration::create(['user_id' => $this->seller->id, 'provider' => 'rajaongkir', 'credentials' => ['api_key' => 'old-seller-key'], 'webhook_token' => Str::random(40), 'status' => 'active']);
        $this->actingAs($this->seller)->postJson('/api/orders/book', ['order_ids' => [Order::first()->id]])->assertOk();
        Http::assertNotSent(fn ($req) => ($req->header('x-api-key')[0] ?? null) === 'old-seller-key');

        $i = $this->actingAs($this->seller)->getJson('/api/integrations')->json('rajaongkir');
        $this->assertTrue($i['connected'] && $i['from_env']);
        $this->assertStringNotContainsString('ship-key', json_encode($i));
        $this->assertStringNotContainsString('deliv-key', json_encode($i));
        $this->actingAs($this->seller)->putJson('/api/integrations/rajaongkir', ['api_key' => 'x'])->assertStatus(422);
        $this->actingAs($this->seller)->putJson('/api/integrations/midtrans', ['server_key' => 'x', 'is_production' => false])->assertStatus(422);
    }
}
