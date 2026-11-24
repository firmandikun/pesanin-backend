<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DropshipProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DropshipFulfilmentQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function digital(User $u, string $slug, array $extra = []): Product
    {
        $p = new Product(['name' => 'Ebook', 'slug' => $slug, 'type' => 'digital', 'price' => 50000, 'status' => 'active', 'payment_methods' => ['bank_transfer', 'epayment']] + $extra);
        $p->user_id = $u->id;
        $p->save();

        return $p;
    }

    private function buy(string $slug, array $extra = [])
    {
        return $this->postJson("/api/public/products/{$slug}/checkout", array_merge(['customer_name' => 'Ani', 'phone' => '081234567890', 'email' => 'ani@mail.com', 'quantity' => 1, 'payment_method' => 'bank_transfer'], $extra));
    }

    public function test_dropship_order_cost_stock_warehouse_queue_and_ship(): void
    {
        Http::fake([
            'tdev.kiriminaja.com/api/mitra/v6.1/addresses*' => fn ($r) => Http::response([
                'status' => true,
                'data' => [
                    str_contains(strtoupper((string) ($r['search'] ?? '')), 'KEBON')
                        ? ['district_id' => 1, 'district_name' => 'KEBON JERUK', 'city_name' => 'JAKARTA BARAT']
                        : ['district_id' => 2, 'district_name' => 'COBLONG', 'city_name' => 'BANDUNG'],
                ],
            ]),
            'tdev.kiriminaja.com/api/mitra/v6.1/shipping_price' => Http::response(['status' => true, 'results' => [
                ['courier' => 'jne', 'courier_name' => 'JNE', 'service_type' => 'REG', 'cost' => 12000, 'etd' => '2-3'],
            ]]),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);
        config(['services.kiriminaja.key' => 'v4.local.k', 'services.kiriminaja.sandbox' => true]);
        $wh = Region::create(['province' => 'DKI JAKARTA', 'city' => 'JAKARTA BARAT', 'district' => 'KEBON JERUK', 'island' => 'Jawa', 'rajaongkir_id' => 1]);
        $dest = Region::create(['province' => 'JAWA BARAT', 'city' => 'BANDUNG', 'district' => 'COBLONG', 'island' => 'Jawa', 'rajaongkir_id' => 2]);
        $dp = DropshipProduct::create(['sku' => 'DS-9', 'product_type' => 'physical', 'name' => 'Blender', 'category' => 'Rumah', 'status' => 'active', 'weight_gram' => 400,
            'stock' => 3, 'stock_set_at' => now()->subDay(), 'warehouse_region_id' => $wh->id, 'wholesale_price' => 50000, 'min_price' => 1, 'suggested_price' => 120000,
            'variants' => [['name' => 'Hijau', 'wholesale_price' => 55000]]]);
        $seller = User::factory()->create(['store_name' => 'Toko A']);
        $p = new Product(['name' => 'Blender A', 'slug' => 'blender-a', 'type' => 'physical', 'price' => 120000, 'weight' => 400, 'status' => 'active',
            'payment_methods' => ['bank_transfer'], 'dropship_product_id' => $dp->id, 'origin_region_id' => $wh->id, 'track_stock' => false, 'allow_oversell' => true,
            'variants' => [['name' => 'Hijau', 'price' => 120000]]]);
        $p->user_id = $seller->id;
        $p->save();

        $body = ['address' => 'Jl. B', 'region_id' => $dest->id, 'variant' => 'Hijau', 'courier' => 'jne:reg'];
        $this->buy('blender-a', $body + ['quantity' => 2])->assertCreated();
        $o = Order::latest('id')->first();
        $this->assertSame([$dp->id, 110000, true, null], [$o->dropship_product_id, (int) $o->dropship_cost, $o->is_dropship, $o->pickup_address_id]);
        $this->buy('blender-a', $body + ['quantity' => 2])->assertStatus(422)->assertJsonFragment(['Stok tidak cukup (tersisa 1).']);

        $this->actingAs($admin)->getJson('/api/admin/dropship/products/'.$dp->id)->assertJsonPath('stock', 1)->assertJsonPath('sold_30d', 0);
        $this->assertSame(0, AppNotification::where('user_id', $admin->id)->count());

        // Seller confirms the buyer's transfer: the order lands in the warehouse queue and admins are told.
        $this->actingAs($seller)->putJson("/api/orders/{$o->id}", ['payment_status' => 'paid'])->assertOk();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'dropship')->count());
        $q = $this->actingAs($admin)->getJson('/api/admin/dropship/orders?tab=perlu_kirim')->assertOk();
        $this->assertSame([$o->code], array_column($q->json('orders.data'), 'code'));
        $this->assertSame('belum', $q->json('orders.data.0.modal_status'));
        $this->actingAs($admin)->getJson('/api/admin/dropship/products/'.$dp->id)->assertJsonPath('sold_30d', 2);

        $this->actingAs($admin)->postJson("/api/admin/dropship/orders/{$o->id}/modal-paid")->assertOk();
        $this->actingAs($admin)->postJson("/api/admin/dropship/orders/{$o->id}/ship", ['tracking_number' => 'JNE123'])->assertOk();
        $o->refresh();
        $this->assertSame(['JNE123', 'in_transit'], [$o->tracking_number, $o->shipping_status]);
        $this->assertNotNull($o->dropship_paid_at);
        $this->assertSame(1, AppNotification::where('user_id', $seller->id)->where('title', 'Pesanan dropship sudah dikirim gudang')->count());
        $this->actingAs($seller)->postJson("/api/admin/dropship/orders/{$o->id}/ship", ['tracking_number' => 'x'])->assertForbidden();
    }

    public function test_orders_over_quota_come_in_locked_until_upgrade(): void
    {
        $seller = User::factory()->create();
        \App\Models\PlatformSetting::write('plans', ['gratis' => ['name' => 'Gratis', 'monthly' => 0, 'limits' => array_merge(\App\Support\Plans::PLANS['gratis']['limits'], ['orders' => 2])]]);
        $this->digital($seller, 'eb-q');
        foreach (range(1, 3) as $i) {
            $this->buy('eb-q', ['customer_name' => "Pembeli {$i}"])->assertCreated();
        }
        $this->assertSame([false, false, true], Order::orderBy('id')->pluck('over_quota')->all());
        $rows = collect($this->actingAs($seller)->getJson('/api/orders?type=digital')->json('orders.data'))->sortBy('id')->values();
        $this->assertSame(['Pembeli 1', 'Pembeli 2', 'Pembeli (terkunci)'], $rows->pluck('customer_name')->all());
        $this->assertNull($rows[2]['phone']);
        $this->assertTrue($rows[2]['locked']);
        $this->assertSame(1, AppNotification::where('user_id', $seller->id)->where('title', 'Kuota pesanan bulan ini sudah habis')->count());

        $seller->forceFill(['plan' => 'pro', 'plan_expires_at' => now()->addMonth()])->save();
        \Illuminate\Support\Facades\Cache::flush();
        $this->assertSame('Pembeli 3', $this->actingAs($seller->fresh())->getJson('/api/orders/'.Order::max('id'))->json('customer_name'));
    }

    public function test_midtrans_failure_voids_the_order(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-p']);
        Http::fake(['*snap/v1/transactions' => Http::response(['error_messages' => ['down']], 500)]);
        $seller = User::factory()->create();
        $this->digital($seller, 'eb-mt');
        $this->buy('eb-mt', ['payment_method' => 'epayment'])->assertStatus(422);
        $o = Order::first();
        $this->assertSame('cancelled', $o->status);
        $this->assertStringContainsString('Midtrans gagal', $o->notes);
    }

    public function test_admin_sets_public_contact(): void
    {
        $this->assertNull($this->getJson('/api/public/site')->json('whatsapp'));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->putJson('/api/admin/platform', ['site' => ['whatsapp' => '6281299990000', 'instagram' => 'https://instagram.com/pesanin.id']])->assertOk();
        $this->actingAs($admin)->putJson('/api/admin/platform', ['site' => ['tiktok' => 'https://evil.example/x']])->assertStatus(422);
        $site = $this->getJson('/api/public/site')->json();
        $this->assertSame(['6281299990000', 'https://instagram.com/pesanin.id'], [$site['whatsapp'], $site['instagram']]);
    }
}
