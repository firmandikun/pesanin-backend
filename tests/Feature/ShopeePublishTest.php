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
            '*get_item_base_info*' => Http::response(['response' => ['item_list' => [['item_id' => 555, 'item_status' => 'REVIEWING']]]]),
            '*category_recommend*' => Http::response(['response' => ['category_id' => [2, 404]]]),
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

    public function test_publish_sends_completed_video_upload_id(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body([
            'video_upload_id' => 'sg-video-upload-1',
        ]))->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item') && $r['video_upload_id'] === 'sg-video-upload-1');
    }

    public function test_video_upload_runs_init_parts_and_complete_flow(): void
    {
        Http::fake([
            '*init_video_upload*' => Http::response(['response' => ['video_upload_id' => 'sg-upload-1', 'part_size' => 5 * 1024 * 1024]]),
            '*upload_video_part*' => Http::response(['error' => '', 'message' => '']),
            '*complete_video_upload*' => Http::response(['error' => '', 'message' => '']),
        ]);

        $this->assertSame('sg-upload-1', (new \App\Support\Shopee\ShopeeClient)->uploadVideo(str_repeat('x', 1024), 'demo.mp4'));

        Http::assertSentCount(3);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'complete_video_upload') && $r['video_upload_id'] === 'sg-upload-1');
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

    public function test_recommend_returns_known_leaf_categories(): void
    {
        $this->fakeShopee();
        $this->getJson('/api/marketplace/publish/recommend?name=Serum+Vitamin+C')->assertOk()->assertExactJson([['id' => 2, 'name' => 'Serum', 'path' => 'Kecantikan › Serum']]);
    }

    /** Seller Centre fields: GTIN, kondisi, produk berbahaya, pre-order, grosir, jasa kirim pilihan, arsipkan. */
    public function test_publish_sends_seller_centre_fields(): void
    {
        $this->fakeShopee([
            ['logistics_channel_id' => 81017, 'logistics_channel_name' => 'J&T', 'enabled' => true],
            ['logistics_channel_id' => 80003, 'logistics_channel_name' => 'Hemat Kargo', 'enabled' => true],
        ]);
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body([
            'gtin' => '00', 'condition' => 'USED', 'dangerous' => true, 'pre_order' => true, 'days_to_ship' => 7,
            'wholesale' => [['min' => 3, 'max' => 10, 'price' => 120000]], 'channels' => ['Hemat Kargo'], 'status' => 'UNLIST',
        ]))->assertOk()->assertJson(['status' => 'UNLIST']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item') && $r['gtin_code'] === '00' && $r['condition'] === 'USED' && $r['item_dangerous'] === 1
            && $r['pre_order'] === ['is_pre_order' => true, 'days_to_ship' => 7] && $r['wholesale'] === [['min_count' => 3, 'max_count' => 10, 'unit_price' => 120000]]
            && $r['logistic_info'] === [['logistic_id' => 80003, 'enabled' => true]] && $r['item_status'] === 'UNLIST');
        $this->assertSame('REVIEWING', MarketplaceListing::sole()->status); // listing follows Shopee's live status (faked as under review)
    }

    public function test_scheduled_publish_is_unlisted_until_then(): void
    {
        $this->fakeShopee();
        $at = now(\App\Support\Dates::TZ)->addDays(2)->setTime(9, 30);
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body(['publish_at' => $at->format('Y-m-d\TH:i')]))->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item') && $r['item_status'] === 'UNLIST' && $r['scheduled_publish_time'] === $at->timestamp);

        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body(['publish_at' => now(\App\Support\Dates::TZ)->addMinutes(10)->format('Y-m-d\TH:i')]))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Jadwal tayang minimal 1 jam dari sekarang.']);
    }

    public function test_no_active_courier_after_selection_is_refused(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body(['channels' => []]))
            ->assertStatus(422)->assertJsonFragment(['message' => 'Produk tidak dapat disimpan jika tidak ada jasa kirim yang aktif.']);
    }

    /** With variations the item-level price can be empty (0): it is taken from the cheapest model. */
    public function test_variants_without_item_price_are_accepted(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body([
            'price' => 0, 'stock' => 0,
            'tiers' => [['name' => 'Ukuran', 'options' => ['5 meter', '10 meter']]],
            'models' => [['index' => [0], 'price' => 28999, 'stock' => 100], ['index' => [1], 'price' => 48980, 'stock' => 100]],
        ]))->assertOk()->assertJson(['listings' => 2]);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'add_item') && $r['original_price'] == 28999 && $r['seller_stock'] === [['stock' => 200]]);
    }

    public function test_variant_price_below_minimum_has_indonesian_message(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body([
            'tiers' => [['name' => 'Ukuran', 'options' => ['5 meter']]], 'models' => [['index' => [0], 'price' => 0, 'stock' => 1]],
        ]))->assertStatus(422)->assertJsonFragment(['message' => 'Harga setiap variasi minimal Rp100.']);
    }

    /** After upload the result carries Shopee's real status (e.g. still under review) and the Seller Centre link. */
    public function test_publish_reports_shopee_status_and_link(): void
    {
        $this->fakeShopee();
        $this->postJson("/api/marketplace/publish/{$this->shop->id}", $this->body())->assertOk()
            ->assertJson(['shopee_status' => 'REVIEWING', 'seller_centre_url' => 'https://seller.shopee.co.id/portal/product/555']);
        $this->assertSame('REVIEWING', MarketplaceListing::sole()->status);
        $this->getJson("/api/marketplace/publish/{$this->shop->id}/items/555")->assertOk()->assertJson(['item_id' => 555, 'shopee_status' => 'REVIEWING']);
    }
}
