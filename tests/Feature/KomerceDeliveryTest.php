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

    public function test_env_keys_are_shown_as_status_only_and_seller_keys_are_ignored(): void
    {
        // Only the integrations status is checked here (no checkout), so no courier call is made.
        $this->seller = User::factory()->create(['store_name' => 'Toko Uji']);
        config(['services.rajaongkir.key' => 'ship-key', 'services.rajaongkir.delivery_key' => 'deliv-key']);
        // An old per-seller key is ignored: the integration status comes from Pesanin's .env keys.
        // (Pesan kurir itself now runs on KiriminAja: see KiriminAjaIntegrationTest.)
        UserIntegration::create(['user_id' => $this->seller->id, 'provider' => 'rajaongkir', 'credentials' => ['api_key' => 'old-seller-key'], 'webhook_token' => Str::random(40), 'status' => 'active']);

        // RajaOngkir is no longer listed (shipping runs on KiriminAja), and no .env key ever reaches the browser.
        $all = $this->actingAs($this->seller)->getJson('/api/integrations')->assertOk();
        $this->assertNull($all->json('rajaongkir'));
        $this->assertStringNotContainsString('ship-key', $all->getContent());
        $this->assertStringNotContainsString('deliv-key', $all->getContent());
        $this->assertStringNotContainsString('old-seller-key', $all->getContent());
        $this->actingAs($this->seller)->putJson('/api/integrations/rajaongkir', ['api_key' => 'x'])->assertStatus(422);
        $this->actingAs($this->seller)->putJson('/api/integrations/midtrans', ['server_key' => 'x', 'is_production' => false])->assertStatus(422);
    }
}
