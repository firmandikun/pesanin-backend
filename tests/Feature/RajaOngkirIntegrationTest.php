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

/** RajaOngkir (Komerce) with faked HTTP: kelurahan search, real rates at checkout, kode pos + kelurahan on the order. */
class RajaOngkirIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_rates_and_checkout_use_rajaongkir(): void
    {
        $seller = User::factory()->create(['store_name' => 'Toko RO']);
        $origin = Region::create(['province' => 'BANTEN', 'city' => 'TANGERANG SELATAN', 'district' => 'SERPONG', 'island' => 'Jawa']);
        $a = new Address(['label' => 'Gudang', 'name' => 'Gudang', 'phone' => '0812', 'address' => 'Jl. A', 'region_id' => $origin->id, 'is_default' => true]);
        $a->user_id = $seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner-ro', 'type' => 'physical', 'price' => 50000, 'weight' => 300, 'status' => 'active', 'payment_methods' => ['cod']]);
        $p->user_id = $seller->id;
        $p->save();
        UserIntegration::create(['user_id' => $seller->id, 'provider' => 'rajaongkir', 'credentials' => ['api_key' => 'ro-key'], 'webhook_token' => Str::random(40), 'status' => 'active']);

        Http::fake([
            'rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => fn ($r) => Http::response(['meta' => ['status' => 'success', 'code' => 200], 'data' => str_contains($r['search'], 'SERPONG')
                ? [['id' => 11, 'label' => 'SERPONG, SERPONG, TANGERANG SELATAN, BANTEN, 15310', 'subdistrict_name' => 'SERPONG', 'district_name' => 'SERPONG', 'city_name' => 'TANGERANG SELATAN', 'province_name' => 'BANTEN', 'zip_code' => '15310']]
                : [['id' => 31555, 'label' => 'CIBUBUR, CIRACAS, JAKARTA TIMUR, DKI JAKARTA, 13720', 'subdistrict_name' => 'CIBUBUR', 'district_name' => 'CIRACAS', 'city_name' => 'JAKARTA TIMUR', 'province_name' => 'DKI JAKARTA', 'zip_code' => '13720']]]),
            'rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response(['meta' => ['status' => 'success', 'code' => 200], 'data' => [
                ['name' => 'Jalur Nugraha Ekakurir (JNE)', 'code' => 'jne', 'service' => 'REG', 'description' => 'Layanan Reguler', 'cost' => 10000, 'etd' => '1-2 day'],
                ['name' => 'ID Express', 'code' => 'ide', 'service' => 'STD', 'description' => 'Standard', 'cost' => 8500, 'etd' => '2-3 day'],
            ]]),
        ]);

        $found = $this->getJson('/api/public/regions?q=cibubur&seller=toner-ro')->assertOk()->json();
        $this->assertSame('CIBUBUR, CIRACAS, JAKARTA TIMUR, DKI JAKARTA 13720', $found[0]['label']);
        $dest = Region::where('rajaongkir_id', 31555)->first();

        $rates = $this->postJson('/api/public/products/toner-ro/quote', ['region_id' => $dest->id, 'quantity' => 1])->json('rates');
        $this->assertSame(['idexpress:std', 'jne:reg'], array_column($rates, 'code'));
        $this->assertSame('1-2 hari', $rates[1]['etd']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'calculate/domestic-cost') && $r['origin'] == 11 && $r['destination'] == 31555 && $r->header('key')[0] === 'ro-key');

        $this->postJson('/api/public/products/toner-ro/checkout', ['customer_name' => 'Ani', 'phone' => '081234567890', 'address' => 'Jl. B', 'region_id' => $dest->id, 'quantity' => 1, 'payment_method' => 'cod', 'courier' => 'jne:reg'])->assertCreated();
        $o = Order::first();
        $this->assertSame(['10000', '13720', 'CIBUBUR', 'jne:reg'], [(string) (int) $o->shipping_cost, $o->postal_code, $o->village, $o->courier_code]);
    }
}
