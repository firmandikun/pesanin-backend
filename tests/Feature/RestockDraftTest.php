<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMaster;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Saran Restock → draft PO per supplier, at the last purchase price, minus what is already on order. */
class RestockDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_groups_by_last_supplier_and_subtracts_open_orders(): void
    {
        $user = User::factory()->create();
        $master = fn (string $sku, int $stock, int $sold) => tap(new StockMaster(['sku' => $sku, 'name' => "Produk {$sku}", 'stock' => $stock, 'tracking_since' => now()->subDays(40)]), function ($m) use ($user, $sold) {
            $m->user_id = $user->id;
            $m->save();
            StockMovement::create(['stock_master_id' => $m->id, 'kind' => 'sale', 'qty' => -$sold, 'stock_after' => $m->stock]);
        });
        $hot = $master('KAOS-M', 10, 60);   // 2/day → 5 days left (kritis); need 2×21 − 10 = 32
        $master('KAOS-L', 200, 30);         // plenty: not suggested

        $sup = new Supplier(['name' => 'Konveksi Jaya', 'phone' => '0811', 'payment_terms_days' => 14]);
        $sup->user_id = $user->id;
        $sup->save();
        $po = fn (string $status, int $qty, int $cost, ?int $supplier, string $on) => tap(new PurchaseOrder(['supplier_id' => $supplier, 'number' => 'PO-'.uniqid(), 'status' => $status, 'ordered_on' => $on]), function ($p) use ($user, $hot, $qty, $cost) {
            $p->user_id = $user->id;
            $p->save();
            $p->items()->create(['stock_master_id' => $hot->id, 'name' => 'Kaos M', 'qty' => $qty, 'unit_cost' => $cost]);
        });
        $po('received', 50, 7500, $sup->id, now()->subDays(30)->toDateString());
        $po('received', 40, 8000, $sup->id, now()->subDays(10)->toDateString());   // latest price
        $po('draft', 12, 8000, null, now()->toDateString());                        // already planned
        $po('cancelled', 99, 1, null, now()->toDateString());                       // ignored

        $p = new Product(['name' => 'Tumbler', 'slug' => 'tumbler', 'type' => 'physical', 'price' => 20000, 'status' => 'active', 'track_stock' => true, 'stock' => 3, 'cost_price' => 5000]);
        $p->user_id = $user->id;
        $p->created_at = now()->subDays(60);
        $p->save();
        foreach (range(1, 3) as $i) {
            $o = new Order(['product_id' => $p->id, 'channel' => 'website', 'source' => 'checkout', 'customer_name' => 'B', 'phone' => '0812', 'quantity' => 10,
                'unit_price' => 20000, 'subtotal' => 200000, 'total' => 200000, 'payment_method' => 'bank_transfer', 'payment_status' => 'paid', 'status' => 'completed', 'shipping_status' => 'delivered']);
            $o->user_id = $user->id;
            $o->save();
        }

        Sanctum::actingAs($user);
        $r = $this->getJson('/api/purchases/restock')->assertOk()->json();

        $this->assertSame(['Konveksi Jaya', null], array_map(fn ($g) => $g['supplier']['name'] ?? null, $r['groups']));
        $a = $r['groups'][0]['items'][0];
        $this->assertSame(['KAOS-M', 'kritis', 32, 12, 20, 8000, true], [$a['sku'], $a['status'], $a['restock_qty'], $a['on_order'], $a['qty'], $a['unit_cost'], $a['last_bought']]);
        $this->assertSame(160000, $r['groups'][0]['total']);
        $b = $r['groups'][1]['items'][0];
        // 30 sold in 30 days → 1/day, 3 left; need 21 − 3 = 18 at HPP (never bought through a PO).
        $this->assertSame(['product', 'Tumbler', 18, 5000, false], [$b['type'], $b['name'], $b['qty'], $b['unit_cost'], $b['last_bought']]);

        // The page turns a group into a draft PO with the existing endpoint.
        $draft = $this->postJson('/api/purchases', ['supplier_id' => $sup->id, 'status' => 'draft', 'ordered_on' => now()->toDateString(),
            'items' => [['type' => 'stock_master', 'id' => $hot->id, 'qty' => 20, 'unit_cost' => 8000]]])->assertCreated();
        $this->assertSame('draft', $draft->json('status'));
        $this->assertSame([], array_values(array_filter($this->getJson('/api/purchases/restock')->json('groups'), fn ($g) => ($g['supplier']['id'] ?? null) === $sup->id)), 'now fully on order');
    }
}
