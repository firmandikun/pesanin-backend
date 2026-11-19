<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\DropshipperProfile;
use App\Models\DropshipProduct;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ongkir only from real courier accounts (no estimates); dropship ships from its warehouse and never offers COD. */
class DropshipShippingTest extends TestCase
{
    use RefreshDatabase;

    private function region(string $city, string $district, int $roId): Region
    {
        return Region::create(['province' => 'DKI JAKARTA', 'city' => $city, 'district' => $district, 'island' => 'Jawa', 'rajaongkir_id' => $roId]);
    }

    private function fakeRajaOngkir(): void
    {
        Http::fake(['rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => Http::response(['meta' => ['status' => 'success', 'code' => 200], 'data' => [
            ['name' => 'Jalur Nugraha Ekakurir (JNE)', 'code' => 'jne', 'service' => 'REG', 'description' => 'Reguler', 'cost' => 12000, 'etd' => '2-3 day'],
        ]])]);
    }

    public function test_seller_product_without_courier_account_gets_no_estimated_rates(): void
    {
        $seller = User::factory()->create();
        $origin = $this->region('JAKARTA BARAT', 'KEBON JERUK', 1);
        $dest = $this->region('JAKARTA TIMUR', 'CIRACAS', 2);
        $a = new Address(['label' => 'Gudang', 'name' => 'G', 'phone' => '0812', 'address' => 'Jl. A', 'region_id' => $origin->id, 'is_default' => true]);
        $a->user_id = $seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner', 'type' => 'physical', 'price' => 50000, 'weight' => 300, 'status' => 'active', 'payment_methods' => ['bank_transfer']]);
        $p->user_id = $seller->id;
        $p->save();
        Http::fake();

        $q = $this->postJson('/api/public/products/toner/quote', ['region_id' => $dest->id, 'quantity' => 1])->assertOk();
        $this->assertSame([], $q->json('rates'));
        $this->assertStringContainsString('layanan ongkir Pesanin belum dikonfigurasi', $q->json('shipping_error'));
        $this->postJson('/api/public/products/toner/checkout', ['customer_name' => 'Ani', 'phone' => '081234567890', 'address' => 'Jl. B', 'region_id' => $dest->id, 'quantity' => 1, 'payment_method' => 'bank_transfer'])
            ->assertStatus(422)->assertJsonFragment(['Ongkir belum tersedia: layanan ongkir Pesanin belum dikonfigurasi.']);
        Http::assertNothingSent();
    }

    public function test_dropship_ships_from_warehouse_and_has_no_cod(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        config(['services.rajaongkir.key' => 'platform-key']);
        $warehouse = $this->region('JAKARTA BARAT', 'KEBON JERUK', 501);
        $dest = $this->region('JAKARTA TIMUR', 'CIRACAS', 777);
        $dp = DropshipProduct::create(['sku' => 'DS-1', 'product_type' => 'physical', 'name' => 'Blender', 'category' => 'Rumah Tangga', 'status' => 'active',
            'weight_gram' => 400, 'stock' => 10, 'warehouse_region_id' => $warehouse->id, 'wholesale_price' => 50000, 'min_price' => 90000, 'suggested_price' => 120000,
            'payment_methods' => ['bank_transfer', 'epayment']]);

        // The seller's own RajaOngkir key is ignored for dropship: the warehouse ships, so the platform admin's account is used.
        $seller = User::factory()->create();
        UserIntegration::create(['user_id' => $seller->id, 'provider' => 'rajaongkir', 'credentials' => ['api_key' => 'seller-key'], 'webhook_token' => Str::random(40), 'status' => 'active']);
        DropshipperProfile::create(['user_id' => $seller->id, 'brand_name' => 'Brand', 'whatsapp' => '0812']);
        $body = ['dropship_product_id' => $dp->id, 'custom_name' => 'Blender Brand', 'selling_price' => 120000, 'slug' => 'blender-brand'];
        $this->actingAs($seller)->postJson('/api/dropship/adoptions', $body + ['payment_methods' => ['cod']])->assertStatus(422);
        $this->actingAs($seller)->postJson('/api/dropship/adoptions', $body + ['payment_methods' => ['bank_transfer', 'epayment']])->assertCreated();
        $product = Product::where('dropship_product_id', $dp->id)->firstOrFail();
        $this->assertSame($warehouse->id, $product->origin_region_id);

        $this->fakeRajaOngkir();
        $show = $this->getJson("/api/public/products/{$product->slug}")->assertOk();
        $this->assertNotContains('cod', $show->json('payment_methods'));
        $this->assertSame('JAKARTA BARAT', $show->json('store.origin'));
        $q = $this->postJson("/api/public/products/{$product->slug}/quote", ['region_id' => $dest->id, 'quantity' => 1])->assertOk();
        $this->assertSame(12000, $q->json('shipping_cost'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'domestic-cost') && $r['origin'] == 501 && $r['destination'] == 777 && $r->header('key')[0] === 'platform-key');

        $this->postJson("/api/public/products/{$product->slug}/checkout", ['customer_name' => 'Ani', 'phone' => '081234567890', 'address' => 'Jl. B', 'region_id' => $dest->id, 'quantity' => 1, 'payment_method' => 'cod'])
            ->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }

    public function test_admin_must_set_warehouse_region_and_cannot_enable_cod(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $region = $this->region('JAKARTA BARAT', 'KEBON JERUK', 1);
        $base = ['product_type' => 'physical', 'name' => 'Lampu', 'category' => 'Dekorasi', 'status' => 'active', 'wholesale_price' => 1, 'min_price' => 1, 'suggested_price' => 1];
        $this->actingAs($admin)->postJson('/api/admin/dropship/products', $base)->assertStatus(422)->assertJsonValidationErrors('warehouse_region_id');
        $this->actingAs($admin)->postJson('/api/admin/dropship/products', $base + ['warehouse_region_id' => $region->id, 'payment_methods' => ['cod']])->assertStatus(422)->assertJsonValidationErrors('payment_methods.0');
        $r = $this->actingAs($admin)->postJson('/api/admin/dropship/products', $base + ['warehouse_region_id' => $region->id])->assertCreated();
        $this->assertSame(['bank_transfer', 'epayment'], $r->json('payment_methods'));
        $this->assertSame('KEBON JERUK, JAKARTA BARAT', $r->json('warehouse_city'));
    }
}
