<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\StockMaster;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\MasterStock;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stok master lintas toko: one stock per SKU across shops, deducted once per order, restored on cancel, pushed to all. */
class StockMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $a;

    private MarketplaceShop $b;

    /** @var array<int, array> update_stock calls: [shop_id, item_id, stock_list] */
    private array $pushed = [];

    /** model_id => reason Shopee refuses. */
    private array $refuse = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create(['plan' => 'bisnis', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->addMonth()]);
        Sanctum::actingAs($this->user);
        $this->a = $this->shop(101, 'Toko A');
        $this->b = $this->shop(202, 'Toko B');
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'update_stock')) {
                parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
                $this->pushed[] = [(int) $q['shop_id'], $req['item_id'], $req['stock_list']];

                $fail = collect($this->refuse)->map(fn ($why, $model) => ['model_id' => $model, 'failed_reason' => $why])->values()->all();

                return Http::response(['response' => ['failure_list' => $fail, 'success_list' => []]]);
            }

            return Http::response(['response' => []]);
        });
    }

    private function shop(int $shopId, string $name): MarketplaceShop
    {
        $s = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => $shopId, 'shop_name' => $name, 'status' => 'active', 'is_demo' => false, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $s->user_id = $this->user->id;
        $s->save();

        return $s;
    }

    private function listing(MarketplaceShop $shop, int $item, int $model, ?string $sku, int $stock): MarketplaceListing
    {
        $l = new MarketplaceListing(['marketplace_shop_id' => $shop->id, 'item_id' => $item, 'model_id' => $model, 'name' => 'Cover Knalpot', 'model_name' => $model ? 'Hitam' : null, 'sku' => $sku, 'price' => 24000, 'stock' => $stock, 'status' => 'NORMAL']);
        $l->user_id = $this->user->id;
        $l->save();

        return $l;
    }

    private function order(MarketplaceShop $shop, string $sn, string $status, array $items, ?int $createTime = null): \App\Models\Order
    {
        return (new ShopeeSync)->upsertOrder($shop, ['order_sn' => $sn, 'order_status' => $status, 'pay_time' => time(), 'create_time' => $createTime ?? time(),
            'item_list' => collect($items)->map(fn ($i) => ['item_id' => $i[0], 'model_id' => $i[1], 'item_name' => 'Cover', 'model_quantity_purchased' => $i[2], 'model_discounted_price' => 24000])->all()]);
    }

    public function test_generate_links_same_sku_across_shops_starting_from_the_lowest_stock(): void
    {
        $la = $this->listing($this->a, 1, 11, 'KNP-HTM', 40);
        $lb = $this->listing($this->b, 2, 22, ' knp-htm ', 25); // same SKU, other case/spaces
        $this->listing($this->a, 3, 0, 'SOLO', 5);              // only one listing → not generated
        $this->listing($this->b, 4, 0, null, 9);                // no SKU

        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk()->assertJson(['masters' => 1, 'listings' => 2]);
        $m = StockMaster::sole();
        $this->assertSame(['KNP-HTM', 25], [$m->sku, $m->stock]);
        $this->assertSame([$m->id, $m->id], [$la->fresh()->stock_master_id, $lb->fresh()->stock_master_id]);
        // Pushed to both shops right away.
        $this->assertEqualsCanonicalizing([[101, 1, [['model_id' => 11, 'seller_stock' => [['stock' => 25]]]]], [202, 2, [['model_id' => 22, 'seller_stock' => [['stock' => 25]]]]]], $this->pushed);
        $this->getJson('/api/marketplace/stock-masters')->assertOk()->assertJson(['stats' => ['masters' => 1, 'linked' => 2, 'without_sku' => 1, 'unlinked_with_sku' => 1, 'candidates' => 0]]);
    }

    public function test_order_deducts_once_and_cancel_restores_once(): void
    {
        $this->listing($this->a, 1, 11, 'KNP', 30);
        $this->listing($this->b, 2, 22, 'KNP', 30);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk();
        $m = StockMaster::sole();

        // Sold 3 in shop A; the same order synced again (webhook + poll) must not deduct twice.
        $this->order($this->a, 'O1', 'READY_TO_SHIP', [[1, 11, 3]]);
        $this->order($this->a, 'O1', 'PROCESSED', [[1, 11, 3]]);
        $this->assertSame(27, $m->fresh()->stock);
        $this->pushed = [];
        MasterStock::pushPending();
        // Shop B (and A) follow: 27.
        $this->assertContains([202, 2, [['model_id' => 22, 'seller_stock' => [['stock' => 27]]]]], $this->pushed);

        $this->order($this->a, 'O1', 'CANCELLED', [[1, 11, 3]]);
        $this->order($this->a, 'O1', 'CANCELLED', [[1, 11, 3]]);
        $this->assertSame(30, $m->fresh()->stock);
        $this->assertSame(['sale', 'restore'], StockMovement::orderBy('id')->pluck('kind')->all());

        // An order first seen already cancelled never touches the stock.
        $this->order($this->b, 'O2', 'CANCELLED', [[2, 22, 5]]);
        $this->assertSame(30, $m->fresh()->stock);
    }

    public function test_orders_from_before_the_master_existed_are_ignored(): void
    {
        $this->listing($this->a, 1, 11, 'KNP', 10);
        $this->listing($this->b, 2, 22, 'KNP', 10);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk();
        $this->order($this->a, 'OLD', 'SHIPPED', [[1, 11, 4]], now()->subDays(3)->timestamp);
        $this->assertSame(10, StockMaster::sole()->stock);
    }

    public function test_manual_edit_from_the_product_list_changes_the_master_for_every_shop(): void
    {
        $la = $this->listing($this->a, 1, 11, 'KNP', 10);
        $this->listing($this->b, 2, 22, 'KNP', 10);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk();
        $this->pushed = [];

        $this->putJson("/api/marketplace/listings/{$la->id}/stock", ['stock' => 50])->assertOk()->assertJson(['stock' => 50]);
        $this->assertSame(50, StockMaster::sole()->stock);
        $this->assertCount(2, $this->pushed);
        $this->assertSame(['adjust', 50 - 10], [StockMovement::sole()->kind, StockMovement::sole()->qty]);
    }

    public function test_new_listing_with_the_same_sku_joins_the_master(): void
    {
        $this->listing($this->a, 1, 11, 'KNP', 10);
        $this->listing($this->b, 2, 22, 'KNP', 10);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk();
        $new = $this->listing($this->b, 9, 0, 'knp', 3);
        MasterStock::autoAttach($new);
        $this->assertSame(StockMaster::sole()->id, $new->fresh()->stock_master_id);
        $this->assertTrue(StockMaster::sole()->push_pending);
    }

    public function test_shopee_refusal_is_kept_on_the_listing(): void
    {
        $this->refuse = [22 => 'stock less than reserved'];
        $this->listing($this->a, 1, 11, 'KNP', 10);
        $lb = $this->listing($this->b, 2, 22, 'KNP', 10);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertOk();
        $this->assertSame('stock less than reserved', $lb->fresh()->stock_push_error);
        $this->getJson('/api/marketplace/stock-masters')->assertJson(['stats' => ['errors' => 1]]);
    }

    public function test_free_plan_cannot_create_masters(): void
    {
        $this->user->forceFill(['plan' => 'gratis', 'plan_expires_at' => null])->save();
        $this->listing($this->a, 1, 11, 'KNP', 10);
        $this->listing($this->b, 2, 22, 'KNP', 10);
        $this->postJson('/api/marketplace/stock-masters/generate')->assertStatus(402);
        $this->assertSame(0, StockMaster::count());
    }
}
