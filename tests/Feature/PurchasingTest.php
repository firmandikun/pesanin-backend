<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMaster;
use App\Models\User;
use App\Support\Purchasing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pembelian & Supplier: PO → goods received (stock + moving-average HPP) → paid (utang, Pengeluaran). */
class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private StockMaster $master;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        Http::fake(['*' => Http::response(['response' => ['failure_list' => [], 'success_list' => []]])]);
        $this->seller = User::factory()->create(['plan' => 'bisnis', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->addMonth()]);
        Sanctum::actingAs($this->seller);

        // Shopee SKU sold in two shops: 10 in stock at HPP 50.000.
        $this->master = new StockMaster(['sku' => 'SRM-C', 'name' => 'Serum Vitamin C', 'stock' => 10]);
        $this->master->user_id = $this->seller->id;
        $this->master->save();
        foreach ([1, 2] as $n) {
            $shop = MarketplaceShop::create(['user_id' => $this->seller->id, 'platform' => 'shopee', 'shop_id' => $n, 'shop_name' => "Toko {$n}", 'status' => 'active',
                'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()]);
            MarketplaceListing::create(['user_id' => $this->seller->id, 'marketplace_shop_id' => $shop->id, 'stock_master_id' => $this->master->id, 'item_id' => $n,
                'model_id' => 0, 'name' => 'Serum Vitamin C', 'sku' => 'SRM-C', 'price' => 89000, 'stock' => 10, 'cost_price' => 50000]);
        }
        // Web product: 4 in stock at HPP 20.000.
        $this->product = new Product(['name' => 'Toner Lemon', 'slug' => 'toner-lemon', 'type' => 'physical', 'price' => 45000, 'status' => 'active', 'track_stock' => true, 'stock' => 4, 'cost_price' => 20000]);
        $this->product->user_id = $this->seller->id;
        $this->product->save();
    }

    private function createPo(array $extra = []): array
    {
        $sup = $this->postJson('/api/suppliers', ['name' => 'CV Kosmetik Jaya', 'phone' => '081277778888', 'payment_terms_days' => 14])->assertCreated()->json('id');

        return $this->postJson('/api/purchases', $extra + [
            'supplier_id' => $sup, 'ordered_on' => now()->toDateString(), 'due_on' => now()->addDays(14)->toDateString(),
            'shipping_cost' => 30000, 'discount' => 0,
            'items' => [['type' => 'stock_master', 'id' => $this->master->id, 'qty' => 20, 'unit_cost' => 44000],
                ['type' => 'product', 'id' => $this->product->id, 'qty' => 6, 'unit_cost' => 15000]],
        ])->assertCreated()->json();
    }

    public function test_full_flow_order_receive_pay(): void
    {
        $po = $this->createPo();
        $this->assertSame([970000, 1000000, 'ordered', 'unpaid'], [$po['subtotal'], $po['total'], $po['status'], $po['payment_status']]);
        $this->assertMatchesRegularExpression('/^PO-\d{4}-001$/', $po['number']);

        // Nothing changes before the goods arrive.
        $this->assertSame(10, $this->master->fresh()->stock);

        $r = $this->postJson("/api/purchases/{$po['id']}/receive")->assertOk()->json();
        // Shipping 30.000 is spread by value: serum line 880.000/970.000 → +1.237/unit, toner 90.000/970.000 → +464/unit.
        $this->assertSame(30, $this->master->fresh()->stock);
        $this->assertSame(10, $this->product->fresh()->stock);
        $landedSerum = 44000 + (int) round(880000 / 970000 * 30000 / 20);
        $this->assertSame((int) round((10 * 50000 + 20 * $landedSerum) / 30), MarketplaceListing::where('item_id', 1)->value('cost_price'));
        $this->assertSame(MarketplaceListing::where('item_id', 1)->value('cost_price'), MarketplaceListing::where('item_id', 2)->value('cost_price'), 'both shops get the new HPP');
        $landedToner = 15000 + (int) round(90000 / 970000 * 30000 / 6);
        $this->assertSame((int) round((4 * 20000 + 6 * $landedToner) / 10), (int) $this->product->fresh()->cost_price);
        $this->assertSame([50000, 20000], array_column($r['changes'], 'hpp_before'));
        $this->assertTrue($this->master->fresh()->movements()->where('kind', 'purchase')->where('qty', 20)->exists());

        // Received only once.
        $this->postJson("/api/purchases/{$po['id']}/receive")->assertStatus(422);

        // Pay in two parts: utang goes down, each payment shows in Pengeluaran (cash, not profit).
        $this->postJson("/api/purchases/{$po['id']}/payments", ['amount' => 400000, 'paid_on' => now()->toDateString(), 'method' => 'transfer'])->assertOk()
            ->assertJsonPath('balance', 600000)->assertJsonPath('payment_status', 'partial');
        $this->getJson('/api/purchases')->assertOk()->assertJsonPath('summary.payable', 600000);
        $this->postJson("/api/purchases/{$po['id']}/payments", ['amount' => 700000, 'paid_on' => now()->toDateString(), 'method' => 'transfer'])->assertStatus(422);
        $last = $this->postJson("/api/purchases/{$po['id']}/payments", ['amount' => 600000, 'paid_on' => now()->toDateString(), 'method' => 'cash'])->assertOk()->json();
        $this->assertSame('paid', $last['payment_status']);
        $this->assertSame([1000000, 'inventory', 'CV Kosmetik Jaya'], [(int) Expense::sum('amount'), Expense::first()->category, Expense::first()->vendor]);

        // Deleting a payment brings the debt and the expense back out.
        $this->deleteJson("/api/purchases/{$po['id']}/payments/{$last['payments'][1]['id']}")->assertOk()->assertJsonPath('balance', 600000);
        $this->assertSame(400000, (int) Expense::sum('amount'));
    }

    public function test_overdue_cancel_and_ownership(): void
    {
        $po = $this->createPo(['ordered_on' => now()->subDays(30)->toDateString(), 'due_on' => now()->subDays(2)->toDateString()]);
        $s = $this->getJson('/api/purchases')->assertOk()->json('summary');
        $this->assertSame([1000000, 1000000, 1], [$s['payable'], $s['overdue'], $s['awaiting_goods']]);
        $this->getJson('/api/suppliers')->assertOk()->assertJsonPath('data.0.owed', 1000000);

        $this->postJson("/api/purchases/{$po['id']}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $this->getJson('/api/purchases')->json('summary.payable'));
        $this->postJson("/api/purchases/{$po['id']}/receive")->assertStatus(422);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/purchases/{$po['id']}")->assertNotFound();
        $this->postJson('/api/purchases', ['ordered_on' => now()->toDateString(), 'items' => [['type' => 'stock_master', 'id' => $this->master->id, 'qty' => 1, 'unit_cost' => 1]]])->assertStatus(422);
    }

    public function test_moving_average_edge_cases(): void
    {
        $this->assertSame(44000, Purchasing::movingAverage(0, 50000, 20, 44000), 'no stock on hand: new cost');
        $this->assertSame(44000, Purchasing::movingAverage(10, 0, 20, 44000), 'no HPP yet: new cost');
        $this->assertSame(44000, Purchasing::movingAverage(-3, 50000, 20, 44000), 'oversold stock counts as zero');
        $this->assertSame(46000, Purchasing::movingAverage(10, 50000, 20, 44000));
    }

    public function test_catalog_lists_stock_masters_and_products(): void
    {
        $items = collect($this->getJson('/api/purchases/catalog?q=serum')->assertOk()->json('items'));
        $this->assertSame([['stock_master', 'SRM-C', 10, 50000]], $items->map(fn ($i) => [$i['type'], $i['sku'], $i['stock'], $i['hpp']])->all());
        $this->assertCount(2, $this->getJson('/api/purchases/catalog')->json('items'));
    }
}
