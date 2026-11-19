<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\ShopeeAdCampaign;
use App\Models\ShopeeAdStat;
use App\Models\User;
use App\Support\AdsGuard;
use App\Support\Dates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Penjaga Iklan: break-even ROAS from HPP + Shopee fees, verdict per ad, daily alert, pause / raise target. */
class AdsGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    private array $edits = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 7, 'shop_name' => 'M storeku', 'status' => 'active', 'is_demo' => false, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $this->user->id;
        $this->shop->save();
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'edit_manual_product_ads')) {
                $this->edits[] = [$req['campaign_id'], $req['edit_action'], $req['roas_target'] ?? null];

                return Http::response(['response' => [['campaign_id' => $req['campaign_id']]]]);
            }

            return Http::response(['response' => []]);
        });
        // No sales history → default fee rate 22%.
        $this->item(1, 'Cover knalpot', 24000, 7135);   // HPP 29.7% → margin 48.3% → break-even ROAS 2.07
        $this->item(2, 'Helm Nielsglos', 138000, 100000); // HPP 72.5% → margin 5.5% → break-even 18.06
        $this->item(3, 'Helm tanpa HPP', 179000, null);
        $this->ad(101, 'Cover knalpot', [1], 100000, 578000, 20, 'auto');  // ROAS 5.78 → sehat
        $this->ad(102, 'Helm Nielsglos', [2], 233000, 552000, 4, 'auto'); // ROAS 2.37 < 18.06 → rugi
        $this->ad(103, 'Kabel', [1], 60000, 0, 0);                       // tanpa penjualan
        $this->ad(104, 'Kecil', [1], 10000, 30000, 1);                    // under min spend → data kurang
        $this->ad(105, 'Helm tanpa HPP', [3], 80000, 200000, 2);          // cannot judge
    }

    private function item(int $id, string $name, int $price, ?int $hpp): void
    {
        $l = new MarketplaceListing(['marketplace_shop_id' => $this->shop->id, 'item_id' => $id, 'model_id' => 0, 'name' => $name, 'price' => $price, 'stock' => 10, 'status' => 'NORMAL', 'cost_price' => $hpp]);
        $l->user_id = $this->user->id;
        $l->save();
    }

    private function ad(int $campaignId, string $name, array $items, int $expense, int $gmv, int $orders, ?string $bidding = null): void
    {
        $c = ShopeeAdCampaign::create(['user_id' => $this->user->id, 'marketplace_shop_id' => $this->shop->id, 'campaign_id' => $campaignId, 'name' => $name, 'ad_type' => 'manual',
            'bidding_method' => $bidding, 'status' => 'ongoing', 'item_ids' => $items]);
        ShopeeAdStat::create(['shopee_ad_campaign_id' => $c->id, 'date' => now(Dates::TZ)->subDay()->toDateString(), 'expense' => $expense, 'gmv' => $gmv, 'orders' => $orders, 'items_sold' => $orders]);
    }

    public function test_verdict_per_ad_from_hpp_and_fees(): void
    {
        $r = $this->getJson('/api/marketplace/ads/guard')->assertOk()->json();
        $by = collect($r['campaigns'])->keyBy('campaign_id');
        $this->assertSame(['rugi', 'tanpa_penjualan', 'hpp_kosong', 'sehat', 'data_kurang'], collect($r['campaigns'])->pluck('status')->all());
        $this->assertEqualsWithDelta(2.07, $by[101]['break_even'], 0.01);
        $this->assertEqualsWithDelta(18.06, $by[102]["break_even"], 0.01);
        $this->assertSame(1.28, $by[101]['break_even_min']); // fees only (1 / 0.78), shown when HPP is missing
        $this->assertLessThan(0, $by[102]['profit']);          // 552k × 5.5% − 233k
        $this->assertSame(['rugi' => 1, 'tanpa_penjualan' => 1], array_intersect_key($r['summary'], ['rugi' => 0, 'tanpa_penjualan' => 0]));
    }

    public function test_daily_alert_once_without_touching_ads_by_default(): void
    {
        $this->assertSame(2, AdsGuard::check($this->user));
        $this->assertSame(0, AdsGuard::check($this->user)); // same day: no repeat
        $n = AppNotification::where('user_id', $this->user->id)->sole();
        $this->assertStringContainsString('Helm Nielsglos', $n->body);
        $this->assertStringContainsString('Kabel', $n->body);
        $this->assertSame([], $this->edits);
    }

    public function test_automatic_pause_or_target_raise_when_enabled(): void
    {
        $this->user->forceFill(['settings' => ['ads_guard' => ['action' => 'raise_target']]])->save();
        AdsGuard::check($this->user->fresh());
        // Losing auto-bidding ad → target raised to 1.2 × break-even; the no-sale ad → paused.
        $this->assertContains([102, 'change_roas_target', 21.7], $this->edits);
        $this->assertContains([103, 'pause', null], $this->edits);
        $this->assertSame('paused', ShopeeAdCampaign::firstWhere('campaign_id', 103)->status);
    }

    public function test_manual_actions_from_the_page(): void
    {
        $id = ShopeeAdCampaign::firstWhere('campaign_id', 102)->id;
        $this->postJson("/api/marketplace/ads/guard/{$id}", ['action' => 'pause'])->assertOk()->assertJson(['status' => 'paused']);
        $this->postJson("/api/marketplace/ads/guard/{$id}", ['action' => 'resume'])->assertOk()->assertJson(['status' => 'ongoing']);
        $this->postJson("/api/marketplace/ads/guard/{$id}", ['action' => 'change_roas_target', 'target' => 20])->assertOk()->assertJson(['roas_target' => 20]);
        $this->assertSame([[102, 'pause', null], [102, 'resume', null], [102, 'change_roas_target', 20]], $this->edits);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson("/api/marketplace/ads/guard/{$id}", ['action' => 'pause'])->assertNotFound();
    }
}
