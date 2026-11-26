<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LandingPageAiTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_ai_landing_page_with_product_and_gemini(): void
    {
        $seller = User::factory()->create(['store_name' => 'Toko Glow']);
        $p = new Product([
            'name' => 'Serum Vitamin C',
            'slug' => 'serum-vit-c',
            'type' => 'physical',
            'description' => 'Mencerahkan kulit kusam dalam 14 hari.',
            'price' => 199000,
            'sale_price' => 129000,
            'images' => ['/storage/products/serum.jpg'],
            'features' => ['Vitamin C 15%', 'BPOM Resmi'],
            'status' => 'active',
        ]);
        $p->user_id = $seller->id;
        $p->save();

        // Without Gemini key -> 422
        $this->actingAs($seller)->postJson('/api/landing-pages/ai', ['product_id' => $p->id])
            ->assertStatus(422);

        UserIntegration::create([
            'user_id' => $seller->id,
            'provider' => 'gemini',
            'credentials' => ['api_key' => 'AIzaTestKey'],
            'webhook_token' => Str::random(40),
            'status' => 'active',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models?*' => Http::response([
                'models' => [['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']]],
            ]),
            'generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'title' => 'Kulit Cerah Alami dengan Serum Vitamin C',
                            'theme' => ['primary' => '#7c3aed'],
                            'seo' => ['title' => 'Serum Vitamin C Original', 'description' => 'Promo spesial hari ini'],
                            'blocks' => [
                                ['type' => 'announcement', 'data' => ['text' => 'PROMO HARI INI']],
                                ['type' => 'headline', 'data' => ['text' => 'Kulit Kusam? Ini Solusinya!', 'align' => 'center', 'size' => 'xl']],
                                ['type' => 'image', 'data' => ['url' => '', 'alt' => 'Serum']],
                                ['type' => 'features', 'data' => ['title' => 'Keunggulan', 'items' => ['BPOM', 'Aman bumil']]],
                                ['type' => 'pricing', 'data' => ['title' => 'Harga Promo', 'normal' => 500000, 'price' => 10000]],
                                ['type' => 'unknown_block_type', 'data' => ['foo' => 'bar']],
                            ],
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $res = $this->actingAs($seller)->postJson('/api/landing-pages/ai', [
            'product_id' => $p->id,
            'brief' => 'Fokus ke wanita usia 25-35 tahun',
            'tone' => 'persuasif',
            'preview' => 'mobile',
        ])->assertStatus(202)->assertJson(['status' => 'running']);

        // Built after the response (no 504 while Gemini thinks); the page polls the status.
        $status = $this->actingAs($seller)->getJson('/api/landing-pages/ai/'.$res->json('job'))->assertOk()->assertJson(['status' => 'done']);
        $page = LandingPage::findOrFail($status->json('page.id'));
        $this->assertSame('Kulit Cerah Alami dengan Serum Vitamin C', $page->title);
        $this->assertSame('draft', $page->status);
        $this->assertSame('#7c3aed', $page->theme['primary']);
        $this->assertSame('narrow', $page->theme['width']);

        $types = array_column($page->blocks, 'type');
        // Unknown block stripped, checkout block auto-appended for product
        $this->assertSame(['announcement', 'headline', 'image', 'features', 'pricing', 'checkout'], $types);
        $this->assertSame('/storage/products/serum.jpg', $page->blocks[2]['data']['url']);
        $this->assertSame(129000, $page->blocks[4]['data']['price']);
        $this->assertSame(199000, $page->blocks[4]['data']['normal']);
        $this->assertSame($p->id, $page->blocks[5]['data']['product_id']);
    }
}
