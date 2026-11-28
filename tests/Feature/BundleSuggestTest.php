<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Saran Paket (products bought together) and Cek Margin's suggested safe price. */
class BundleSuggestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    private int $sn = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->shop = MarketplaceShop::create(['user_id' => $this->user->id, 'platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'Toko A', 'status' => 'active']);
    }

    private function listing(int $item, string $name, int $price, ?int $cost = null, int $model = 0): void
    {
        MarketplaceListing::create(['user_id' => $this->user->id, 'marketplace_shop_id' => $this->shop->id, 'item_id' => $item, 'model_id' => $model,
            'name' => $name, 'model_name' => $model ? "Var {$model}" : null, 'price' => $price, 'stock' => 10, 'cost_price' => $cost]);
    }

    /** @param array<int, array{0: int, 1: int}> $lines [item_id, paid price] */
    private function order(array $lines, string $status = 'completed'): void
    {
        $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id, 'external_order_sn' => 'SN'.(++$this->sn),
            'external_status' => $status === 'cancelled' ? 'CANCELLED' : 'COMPLETED', 'customer_name' => 'B', 'quantity' => count($lines), 'unit_price' => 1, 'subtotal' => 1, 'total' => 1,
            'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => $status, 'shipping_status' => 'delivered',
            'items' => array_map(fn ($l) => ['item_id' => $l[0], 'model_id' => 0, 'qty' => 1, 'price' => $l[1]], $lines)]);
        $o->user_id = $this->user->id;
        $o->created_at = now()->subDays(3);
        $o->save();
    }

    public function test_suggests_pairs_bought_together_most_first(): void
    {
        $this->listing(1, 'Helm', 150000);
        $this->listing(2, 'Kaca Helm', 40000, null, 11);
        $this->listing(2, 'Kaca Helm', 45000, null, 12);
        $this->listing(3, 'Sarung Tangan', 30000);
        $this->listing(4, 'Masker', 10000);
        foreach (range(1, 4) as $_) {
            $this->order([[1, 150000], [2, 40000]]);
        }
        $this->order([[1, 150000], [3, 30000]]);
        $this->order([[1, 150000], [3, 30000]]);
        $this->order([[3, 30000], [4, 10000]]);                 // only once: below the minimum
        $this->order([[1, 150000], [4, 10000]], 'cancelled');    // cancelled orders don't count
        $this->order([[4, 10000], [4, 10000]]);                  // same product twice is not a pair

        Sanctum::actingAs($this->user);
        $r = $this->getJson('/api/marketplace/listings/bundle-suggestions')->assertOk()->json();

        $this->assertSame([['Helm', 'Kaca Helm', 4], ['Helm', 'Sarung Tangan', 2]],
            array_map(fn ($p) => [$p['items'][0]['name'], $p['items'][1]['name'], $p['together']], $r['pairs']));
        $first = $r['pairs'][0];
        $this->assertSame(100, $first['confidence'], 'every Kaca Helm order also had a Helm');
        $this->assertSame([40000, 45000], [$first['items'][1]['price'], $first['items'][1]['price_max']]);
        $this->assertCount(2, $first['items'][1]['models']);
        $this->assertSame(190000, $first['pair_price']);
        $this->assertSame(['orders' => 8, 'multi_item_orders' => 7, 'days' => 90], $r['summary']);
    }

    public function test_skips_products_no_longer_on_sale(): void
    {
        $this->listing(1, 'Helm', 150000);
        foreach (range(1, 3) as $_) {
            $this->order([[1, 150000], [2, 40000]]);
        }
        Sanctum::actingAs($this->user);
        $this->assertSame([], $this->getJson('/api/marketplace/listings/bundle-suggestions')->assertOk()->json('pairs'));
    }

    public function test_cek_margin_suggests_list_price_that_keeps_the_discount_ratio(): void
    {
        // Listed 50.000 but buyers paid 40.000 (20% discount); HPP 40.000 → losing money.
        $this->listing(1, 'Helm', 50000, 40000);
        $this->order([[1, 40000]]);
        Sanctum::actingAs($this->user);
        $row = $this->getJson('/api/marketplace/listings/margins')->assertOk()->json('rows.0');
        $this->assertSame('rugi', $row['status']);
        // min price = 40.000 / (1 − 10% fee − 10% margin) = 50.000; keep the 80% paid ratio → list 62.500.
        $this->assertSame(50000, $row['min_price']);
        $this->assertSame(62500, $row['suggested_price']);
    }
}
