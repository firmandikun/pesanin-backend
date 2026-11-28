<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Reorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Waktunya beli lagi: buyers one cycle after their last paid order, reminded once until they buy again. */
class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private function product(string $slug, string $type = 'physical'): Product
    {
        $p = new Product(['name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug, 'type' => $type, 'price' => 50000, 'status' => 'active']);
        $p->user_id = $this->seller->id;
        $p->save();

        return $p;
    }

    private function order(Product $p, string $phone, string $name, int $daysAgo, string $status = 'completed', string $channel = 'website'): void
    {
        $o = new Order(['channel' => $channel, 'product_id' => $p->id, 'customer_name' => $name, 'phone' => $phone, 'quantity' => 1, 'unit_price' => 50000,
            'subtotal' => 50000, 'total' => 50000, 'payment_method' => 'cod', 'payment_status' => 'paid', 'status' => $status, 'shipping_status' => 'delivered']);
        $o->user_id = $this->seller->id;
        $o->created_at = now()->subDays($daysAgo);
        $o->save();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create(['store_name' => 'Toko Serum']);
        $serum = $this->product('serum-vit-c');
        $ebook = $this->product('ebook-skincare', 'digital');
        $this->order($serum, '081211110001', 'Rina', 35);                 // due
        $this->order($serum, '+62 812-1111-0002', 'Dewi', 40);            // due (phone format differs)
        $this->order($serum, '081211110002', 'Dewi', 45);
        $this->order($serum, '081211110003', 'Budi', 33);                 // bought again recently → not due
        $this->order($serum, '081211110003', 'Budi', 5);
        $this->order($serum, '081211110004', 'Sari', 10);                 // not a full cycle yet
        $this->order($serum, '081211110005', 'Tono', 90);                 // too long ago (outside window)
        $this->order($serum, '081211110006', 'Ani', 36, 'cancelled');      // cancelled
        $this->order($ebook, '081211110007', 'Joko', 40);                 // digital only
        $this->order($serum, '081211110008', 'Wati', 40, 'completed', 'shopee'); // Shopee buyer: not ours to message
    }

    public function test_due_customers_and_message(): void
    {
        $due = Reorder::due($this->seller);
        $this->assertSame(['Dewi', 'Rina'], array_column($due, 'name'));
        $this->assertSame([2, 1], array_column($due, 'orders'));
        $this->assertSame('6281211110002', $due[0]['phone']);

        $msg = Reorder::message($this->seller, $due[1]);
        $this->assertStringContainsString('Halo kak Rina', $msg);
        $this->assertStringContainsString('Serum vit c di Toko Serum', $msg);
        $this->assertStringContainsString('/c/serum-vit-c', $msg);
    }

    public function test_contacted_customer_hides_until_next_purchase_and_settings_apply(): void
    {
        Sanctum::actingAs($this->seller);
        $this->getJson('/api/customers/reorder')->assertOk()->assertJsonCount(2, 'customers');
        $this->postJson('/api/customers/reorder/contacted', ['phone' => '081211110001', 'name' => 'Rina'])->assertNoContent();
        $this->assertSame(['Dewi'], array_column($this->getJson('/api/customers/reorder')->json('customers'), 'name'));
        $this->assertNotNull(Customer::where('user_id', $this->seller->id)->where('phone', '6281211110001')->value('reorder_contacted_at'));

        // Shorter cycle: Sari (10 days) becomes due; custom message is used.
        $this->putJson('/api/customers/reorder/settings', ['days' => 7, 'message' => 'Hai {nama}, {produk} tinggal sedikit?'])->assertOk()->assertJsonPath('days', 7);
        $r = collect($this->getJson('/api/customers/reorder')->json('customers'))->keyBy('name');
        $this->assertTrue($r->has('Sari'));
        $this->assertSame('Hai Sari, Serum vit c tinggal sedikit?', $r['Sari']['message']);
    }

    public function test_daily_alert_lists_new_due_customers_once(): void
    {
        $this->assertSame(2, Reorder::notify($this->seller));
        $n = AppNotification::where('user_id', $this->seller->id)->where('type', 'reorder')->sole();
        $this->assertSame('2 pelanggan waktunya beli lagi', $n->title);
        $this->assertSame('/customers/reorder', $n->url);
        $this->assertSame(0, Reorder::notify($this->seller), 'same customers are not announced again');
    }

    public function test_broadcast_can_target_the_segment(): void
    {
        Sanctum::actingAs($this->seller);
        $r = $this->postJson('/api/broadcasts/preview', ['audience' => ['source' => 'reorder']])->assertOk()->json();
        $this->assertSame(2, $r['count']);
    }
}
