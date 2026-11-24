<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use App\Support\KiriminAja\CourierBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** KiriminAja with faked HTTP: region search, shipping rates, checkout, pickup booking, and tracking. */
class KiriminAjaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_rates_checkout_booking_and_tracking_use_kiriminaja(): void
    {
        $seller = User::factory()->create(['store_name' => 'Toko KA', 'whatsapp' => '081200000000']);
        $origin = Region::create(['province' => 'BANTEN', 'city' => 'TANGERANG SELATAN', 'district' => 'SERPONG', 'island' => 'Jawa', 'postal_code' => '15310']);
        $a = new Address(['label' => 'Gudang', 'name' => 'Gudang', 'phone' => '081200000000', 'address' => 'Jl. A', 'region_id' => $origin->id, 'is_default' => true]);
        $a->user_id = $seller->id;
        $a->save();
        $p = new Product(['name' => 'Toner', 'slug' => 'toner-ka', 'type' => 'physical', 'price' => 50000, 'weight' => 300, 'status' => 'active', 'payment_methods' => ['cod']]);
        $p->user_id = $seller->id;
        $p->save();

        config([
            'services.kiriminaja.key' => 'v4.local.test-key',
            'services.kiriminaja.sandbox' => true,
            'services.kiriminaja.delivery' => true,
            'services.kiriminaja.prefix' => 'PSN',
        ]);

        Http::fake([
            'tdev.kiriminaja.com/api/mitra/v6.1/addresses*' => fn (HttpRequest $r) => Http::response([
                'status' => true,
                'code' => 200,
                'data' => str_contains(strtoupper((string) ($r['search'] ?? '')), 'SERPONG')
                    ? [['province_id' => 3, 'city_id' => 457, 'district_id' => 5783, 'subdistrict_id' => 11, 'full_address' => 'Serpong, Serpong, Tangerang Selatan, Banten, 15310']]
                    : [['province_id' => 6, 'city_id' => 154, 'district_id' => 5507, 'subdistrict_id' => 31555, 'full_address' => 'Cibubur, Ciracas, Jakarta Timur, DKI Jakarta, 13720']],
            ]),
            'tdev.kiriminaja.com/api/mitra/v6.1/shipping_price' => Http::response([
                'status' => true,
                'code' => 200,
                'results' => [
                    ['service' => 'jne', 'service_name' => 'JNE Express Reguler', 'service_type' => 'REG', 'cost' => '10000', 'etd' => '1-2', 'cod' => true],
                    ['service' => 'ide', 'service_name' => 'ID Express STD', 'service_type' => 'STD', 'cost' => '8500', 'etd' => '2-3', 'cod' => true],
                ],
            ]),
            'tdev.kiriminaja.com/api/mitra/v6.2/request_pickup' => Http::response([
                'status' => true,
                'code' => 200,
                'data' => ['order_id' => 'KA-001', 'receipt' => 'JNE123456'],
            ]),
            'tdev.kiriminaja.com/api/mitra/v6.1/tracking' => Http::response([
                'status' => true,
                'code' => 200,
                'data' => [
                    'summary' => ['status' => 'Delivered'],
                    'history' => [
                        ['date' => '2026-11-24 14:00:00', 'desc' => 'Paket diterima', 'location' => 'Jakarta'],
                    ],
                ],
            ]),
        ]);

        // 1. Region search
        $found = $this->getJson('/api/public/regions?q=cibubur&seller=toner-ka')->assertOk()->json();
        $this->assertSame('CIBUBUR, CIRACAS, JAKARTA TIMUR, DKI JAKARTA 13720', $found[0]['label']);
        $dest = Region::findOrFail($found[0]['id']);

        // 2. Quote / shipping rates
        $rates = $this->postJson('/api/public/products/toner-ka/quote', ['region_id' => $dest->id, 'quantity' => 1])->json('rates');
        $this->assertSame(['idexpress:std', 'jne:reg'], array_column($rates, 'code'));
        $this->assertSame('kiriminaja', $rates[1]['provider']);
        $this->assertSame('1-2 hari', $rates[1]['etd']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/mitra/v6.1/shipping_price')
            && $r['origin'] == 5783
            && $r['destination'] == 5507
            && $r['insurance'] === 0
            && $r->header('Authorization')[0] === 'Bearer v4.local.test-key');

        // 3. Checkout
        $this->postJson('/api/public/products/toner-ka/checkout', [
            'customer_name' => 'Ani', 'phone' => '081234567890', 'address' => 'Jl. B',
            'region_id' => $dest->id, 'quantity' => 1, 'payment_method' => 'cod', 'courier' => 'jne:reg',
        ])->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame(['10000', '13720', 'CIBUBUR', 'jne:reg'], [(string) (int) $order->shipping_cost, $order->postal_code, $order->village, $order->courier_code]);

        // 4. Order rates & book pickup (with expedition override + object metadata)
        $orderRates = $this->actingAs($seller)->getJson("/api/orders/{$order->id}/shipping-rates")->assertOk()->json('rates');
        $this->assertSame(['idexpress:std', 'jne:reg'], array_column($orderRates, 'code'));

        $book = $this->actingAs($seller)->postJson('/api/orders/book', [
            'order_ids' => [$order->id],
            'courier' => 'JNE',
            'courier_code' => 'jne:reg',
            'courier_service' => 'REG',
            'shipping_cost' => 10000,
        ])->assertOk();
        $this->assertSame([['code' => $order->code, 'waybill' => 'JNE123456']], $book->json('shipped'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/mitra/v6.2/request_pickup')
            && str_starts_with((string) ($r['packages'][0]['order_id'] ?? ''), 'PSN-')
            && is_string($r['packages'][0]['items'][0]['metadata']['sku'] ?? null)
            && is_string($r['packages'][0]['items'][0]['metadata']['variant_label'] ?? null));
        $order->refresh();
        $this->assertSame(['KA-001', 'kiriminaja', 'JNE123456'], [$order->shipping_ref, $order->shipping_provider, $order->tracking_number]);
        $this->assertSame(0, CourierBooking::auto(), 'already booked');

        // 5. Tracking
        $track = $this->actingAs($seller)->getJson("/api/orders/{$order->id}/tracking")->assertOk()->json();
        $this->assertSame('kiriminaja', $track['source']);
        $this->assertSame('Delivered', $track['status']);
        $this->assertSame('Paket diterima', $track['events'][0]['text']);

        // 6. Integrations endpoint reports kiriminaja active
        $int = $this->actingAs($seller)->getJson('/api/integrations')->assertOk()->json('kiriminaja');
        $this->assertTrue($int['connected']);
        $this->assertFalse($int['is_production']);
    }
}
