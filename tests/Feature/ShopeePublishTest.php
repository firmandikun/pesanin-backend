<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Upload produk ke Shopee (faked v2.product APIs): category picker, single item, variants. */
class ShopeePublishTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'Toko A', 'status' => 'active', 'region' => 'ID', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $this->user->id;
        $this->shop->save();
    }

    private function fakeShopee(array $channels = [['logistics_channel_id' => 81017, 'logistics_channel_name' => 'J&T', 'enabled' => true]]): void
    {
        Http::fake([
            '*get_category*' => Http::response(['response' => ['category_list' => [
                ['category_id' => 1, 'parent_category_id' => 0, 'display_category_name' => 'Kecantikan', 'original_category_name' => 'Beauty', 'has_children' => true],
                ['category_id' => 2, 'parent_category_id' => 1, 'display_category_name' => 'Serum', 'original_category_name' => 'Serum', 'has_children' => false],
            ]]]),
            '*get_channel_list*' => Http::response(['response' => ['logistics_channel_list' => $channels]]),
            '*add_item*' => Http::response(['response' => ['item_id' => 555]]),
            '*init_tier_variation*' => Http::response(['response' => ['item_id' => 555, 'model' => [['model_id' => 71, 'tier_index' => [0]], ['model_id' => 72, 'tier_index' => [1]]]]]),
        ]);
    }

    private function body(array $extra = []): array
    {
        return $extra + ['name' => 'Serum Vitamin C', 'description' => 'Serum wajah', 'category_id' => 2, 'brand_id' => 0, 'price' => 129000, 'stock' => 10,
            'weight' => 150, 'image_ids' => ['img1', 'img2'], 'attributes' => [['id' => 200010, 'value_ids' => [100593]], ['id' => 200061, 'text' => '1825545600'], ['id' => 999, 'text' => '']]];
    }

    public function test_categories_are_leaves_with_path(): void
    {
        $this->fakeShopee();
        $this->getJson('/api/marketplace/publish/categories')->assertOk()->assertExactJson([['id' => 2, 'name' => 'Serum', 'path' => 'Kecantikan › Serum']]);
    }

    public function test_publish_single_item_links_listing_to_product(): void
    {
        $this->fakeShopee();
        $p = new Product(['name' => 'Serum', 'slug' => 'serum', 'type' => 'physical', 'price' => 129000, 'cost_price' => 40000, 'status' => 'active']);
        $p->user_id = $this->user->id;
        $p->save();

        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body(['product_id' => $p->id]))->assertOk()->assertJson(['item_id' => 555, 'listings' => 1]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item')
            && $r['weight'] == 0.15 && $r['logistic_info'] === [['logistic_id' => 81017, 'enabled' => true]]
            && $r['attribute_list'] === [['attribute_id' => 200010, 'attribute_value_list' => [['value_id' => 100593]]], ['attribute_id' => 200061, 'attribute_value_list' => [['value_id' => 0, 'original_value_name' => '1825545600']]]]);
        $l = MarketplaceListing::sole();
        $this->assertSame([555, 0, $p->id, 40000], [(int) $l->item_id, (int) $l->model_id, $l->product_id, (int) $l->cost_price]);
    }

    public function test_publish_with_variants(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body([
            'tiers' => [['name' => 'Ukuran', 'options' => ['20ml', '30ml']]],
            'models' => [['index' => [0], 'price' => 129000, 'stock' => 5, 'sku' => 'S20'], ['index' => [1], 'price' => 169000, 'stock' => 3, 'sku' => 'S30']],
        ]))->assertOk()->assertJson(['listings' => 2]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item') && $r['original_price'] == 129000 && $r['seller_stock'] === [['stock' => 8]]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'init_tier_variation') && $r['tier_variation'][0]['option_list'][1] === ['option' => '30ml'] && $r['model'][1]['model_sku'] === 'S30');
        $this->assertSame(['20ml', '30ml'], MarketplaceListing::orderBy('model_id')->pluck('model_name')->all());
    }

    public function test_shop_without_courier_is_refused(): void
    {
        $this->fakeShopee([]);
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body())->assertStatus(422)->assertJsonFragment(['message' => 'Belum ada jasa kirim aktif di toko ini. Aktifkan di Seller Centre → Pengaturan Pengiriman.']);
    }
}
