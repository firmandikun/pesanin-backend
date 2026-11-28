<?php

namespace Tests\Feature;

use App\Models\CsStaff;
use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** CS penanggung jawab per order (round-robin among CS on shift), Kinerja CS, and Cek Ongkir. */
class CsAssignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-25 10:00')); // Wednesday
        $this->user = User::factory()->create(['whatsapp' => '081111111111']);
        $this->user->followupTemplates()->create(['event' => 'redirect', 'name' => 'Redirect', 'message' => 'Halo, order {{order_id}}', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cs(string $name, string $phone, array $attrs = []): CsStaff
    {
        $cs = new CsStaff($attrs + ['name' => $name, 'phone' => $phone, 'is_active' => true]);
        $cs->user_id = $this->user->id;
        $cs->save();

        return $cs;
    }

    private function product(string $slug, array $success = ['action' => 'whatsapp_random']): Product
    {
        $p = new Product(['name' => 'Ebook', 'slug' => $slug, 'type' => 'digital', 'price' => 50000, 'status' => 'active', 'payment_methods' => ['bank_transfer'], 'success_settings' => $success]);
        $p->user_id = $this->user->id;
        $p->save();

        return $p;
    }

    private function buy(string $slug, string $phone = '081234567890')
    {
        return $this->postJson("/api/public/products/{$slug}/checkout", ['customer_name' => 'Ani', 'phone' => $phone, 'email' => 'ani@mail.com', 'quantity' => 1, 'payment_method' => 'bank_transfer'])->assertCreated();
    }

    public function test_orders_go_to_cs_in_turn_and_buyer_is_sent_to_that_cs(): void
    {
        $rina = $this->cs('Rina', '082222222222');
        $dimas = $this->cs('Dimas', '083333333333');
        $this->cs('Off', '084444444444', ['is_active' => false]);
        $this->product('ebook-wa');

        $first = $this->buy('ebook-wa');
        $second = $this->buy('ebook-wa', '081234567891');
        $third = $this->buy('ebook-wa', '081234567892');

        $this->assertSame([$rina->id, $dimas->id, $rina->id], Order::orderBy('id')->pluck('cs_staff_id')->all());
        $this->assertStringContainsString('6282222222222', $first->json('whatsapp_url'));
        $this->assertStringContainsString('6283333333333', $second->json('whatsapp_url'));
        $this->assertStringContainsString('6282222222222', $third->json('whatsapp_url'));
    }

    public function test_on_shift_cs_first_and_product_can_limit_the_cs(): void
    {
        $offShift = $this->cs('Malam', '082222222222', ['schedule' => ['wednesday' => ['on' => true, 'start' => '20:00', 'end' => '23:59']]]);
        $day = $this->cs('Siang', '083333333333', ['schedule' => ['wednesday' => ['on' => true, 'start' => '08:00', 'end' => '17:00']]]);
        $other = $this->cs('Lain', '084444444444');
        $this->product('ebook-a', ['action' => 'page', 'cs_staff_ids' => [$offShift->id, $day->id]]);

        $this->buy('ebook-a');
        $this->buy('ebook-a', '081234567891');
        $this->assertSame([$day->id, $day->id], Order::orderBy('id')->pluck('cs_staff_id')->all(), 'only Siang is on shift; Lain is not on this product');

        // Nobody on shift: the order still gets an owner.
        Carbon::setTestNow(Carbon::parse('2026-11-25 18:00'));
        $this->buy('ebook-a', '081234567892');
        $this->assertSame($offShift->id, Order::latest('id')->value('cs_staff_id'));
        $this->assertNull($other->fresh()->last_assigned_at);
    }

    public function test_no_cs_keeps_store_number(): void
    {
        $this->product('ebook-solo');
        $r = $this->buy('ebook-solo');
        $this->assertNull(Order::sole()->cs_staff_id);
        $this->assertStringContainsString('6281111111111', $r->json('whatsapp_url'));
    }

    public function test_filter_reassign_followup_and_summary(): void
    {
        $rina = $this->cs('Rina', '082222222222');
        $dimas = $this->cs('Dimas', '083333333333');
        $this->product('ebook-wa');
        $this->buy('ebook-wa');
        $this->buy('ebook-wa', '081234567891');
        [$a, $b] = Order::orderBy('id')->get()->all();
        $a->forceFill(['payment_status' => 'paid'])->save();

        Sanctum::actingAs($this->user);
        $list = $this->getJson("/api/orders?cs_staff_id={$rina->id}")->assertOk()->json('orders.data');
        $this->assertSame([[$a->id, 'Rina']], array_map(fn ($o) => [$o['id'], $o['cs_staff']['name']], $list));

        $this->postJson("/api/orders/{$b->id}/followup", ['event' => 'redirect'])->assertOk();
        $this->assertNotNull($b->fresh()->cs_contacted_at);

        $this->putJson("/api/orders/{$b->id}/cs", ['cs_staff_id' => $rina->id])->assertOk()->assertJsonPath('cs_staff.name', 'Rina');
        $this->putJson("/api/orders/{$a->id}/cs", ['cs_staff_id' => null])->assertOk();
        $this->assertSame(1, count($this->getJson('/api/orders?cs_staff_id=none')->json('orders.data')));

        $other = User::factory()->create();
        $foreign = new CsStaff(['name' => 'X', 'phone' => '1', 'is_active' => true]);
        $foreign->user_id = $other->id;
        $foreign->save();
        $this->putJson("/api/orders/{$a->id}/cs", ['cs_staff_id' => $foreign->id])->assertStatus(422);

        $rows = collect($this->getJson('/api/orders/cs-summary')->assertOk()->json('rows'))->keyBy('name');
        $this->assertSame(['orders' => 1, 'paid' => 0, 'contacted' => 1], collect($rows['Rina'])->only(['orders', 'paid', 'contacted'])->all());
        $this->assertSame(['orders' => 1, 'paid' => 1, 'revenue' => 50000, 'closing_rate' => 100], collect($rows['Tanpa CS'])->only(['orders', 'paid', 'revenue', 'closing_rate'])->all());
        $this->assertArrayNotHasKey('Dimas', $rows->all());
        $this->assertNotNull($dimas);
    }

    public function test_cek_ongkir_lists_rates_cheapest_first(): void
    {
        Http::fake([
            'tdev.kiriminaja.com/api/mitra/v6.1/addresses*' => fn ($r) => Http::response(['status' => true, 'data' => [
                str_contains(strtoupper((string) ($r['search'] ?? '')), 'NGAGLIK')
                    ? ['district_id' => 1, 'district_name' => 'NGAGLIK', 'city_name' => 'SLEMAN']
                    : ['district_id' => 2, 'district_name' => 'BAGELEN', 'city_name' => 'PURWOREJO'],
            ]]),
            'tdev.kiriminaja.com/api/mitra/v6.1/shipping_price' => Http::response(['status' => true, 'results' => [
                ['courier' => 'jne', 'courier_name' => 'JNE', 'service_type' => 'REG', 'cost' => 18000, 'etd' => '6'],
                ['courier' => 'sicepat', 'courier_name' => 'SiCepat', 'service_type' => 'REG', 'cost' => 12000, 'etd' => '2-3'],
            ]]),
        ]);
        config(['services.kiriminaja.key' => 'v4.local.k', 'services.kiriminaja.sandbox' => true]);
        $from = Region::create(['province' => 'DI YOGYAKARTA', 'city' => 'SLEMAN', 'district' => 'NGAGLIK', 'island' => 'Jawa', 'rajaongkir_id' => 1]);
        $to = Region::create(['province' => 'JAWA TENGAH', 'city' => 'PURWOREJO', 'district' => 'BAGELEN', 'island' => 'Jawa', 'rajaongkir_id' => 2]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/shipping/check', ['region_id' => $to->id, 'weight' => 1000])->assertStatus(422);
        $address = $this->user->addresses()->create(['label' => 'Gudang', 'name' => 'Dwiky', 'phone' => '0812', 'address' => 'Jl. A', 'region_id' => $from->id, 'is_default' => true]);

        $r = $this->postJson('/api/shipping/check', ['address_id' => $address->id, 'region_id' => $to->id, 'weight' => 1000])->assertOk()->json();
        $this->assertSame([['sicepat', 12000], ['jne', 18000]], array_map(fn ($x) => [$x['courier'], $x['cost']], $r['rates']));
        $this->assertNull($r['error']);
        $this->assertStringContainsString('PURWOREJO', strtoupper($r['destination']));
    }
}
