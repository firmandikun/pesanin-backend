<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Iklan Meta: token connect, insights sync (faked Graph API), spend mirrored to ad_spends, real ROAS from orders. */
class MetaAdsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_id' => null, 'services.meta.app_secret' => null]);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function fakeGraph(): void
    {
        $d = fn ($n) => now()->subDays($n)->toDateString();
        Http::fake([
            'graph.facebook.com/*/me?*' => Http::response(['id' => '77', 'name' => 'Firman']),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_1', 'account_id' => '1', 'name' => 'Iklan Toko', 'account_status' => 1, 'currency' => 'IDR', 'amount_spent' => '1500000', 'spend_cap' => '0', 'balance' => '250000'],
            ]]),
            'graph.facebook.com/*/act_1/campaigns*' => Http::response(['data' => [
                ['id' => 'c1', 'name' => 'Serum-Retarget-Nov', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES'],
                ['id' => 'c2', 'name' => 'Toner-WA', 'effective_status' => 'PAUSED', 'objective' => 'OUTCOME_ENGAGEMENT'],
            ]]),
            'graph.facebook.com/*/me/permissions*' => Http::response(['success' => true]),
            'graph.facebook.com/*/act_1/insights*' => fn ($r) => str_contains($r->url(), 'time_increment') ? Http::response(['data' => [
                ['campaign_id' => 'c1', 'campaign_name' => 'Serum-Retarget-Nov', 'date_start' => $d(1), 'spend' => '100000', 'impressions' => '5000', 'reach' => '3000', 'clicks' => '120',
                    'results' => [['indicator' => 'actions:omni_landing_page_view', 'values' => [['value' => '80']]]],
                    'actions' => [['action_type' => 'omni_purchase', 'value' => '3']], 'action_values' => [['action_type' => 'omni_purchase', 'value' => '450000']]],
                ['campaign_id' => 'c1', 'campaign_name' => 'Serum-Retarget-Nov', 'date_start' => $d(0), 'spend' => '50000', 'impressions' => '2000', 'clicks' => '40'],
                ['campaign_id' => 'c2', 'campaign_name' => 'Toner-WA', 'date_start' => $d(0), 'spend' => '30000', 'impressions' => '1000', 'clicks' => '30',
                    'actions' => [['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '12']]],
            ]]) : Http::response(['data' => str_contains($r->url(), 'level=campaign')
                ? [['campaign_id' => 'c1', 'reach' => '4100'], ['campaign_id' => 'c2', 'reach' => '900']]
                : [['reach' => '4500']]]),
        ]);
    }

    public function test_connect_sync_and_report_real_roas(): void
    {
        $this->fakeGraph();
        $this->postJson('/api/meta-ads/token', ['access_token' => str_repeat('x', 40)])->assertOk()->assertJson(['name' => 'Firman']);
        $status = $this->getJson('/api/meta-ads')->assertOk()->json();
        $this->assertTrue($status['connected']);
        $this->assertEquals(['id' => 'act_1', 'balance' => 250000, 'amount_spent' => 1500000], array_intersect_key($status['accounts'][0], ['id' => 0, 'balance' => 0, 'amount_spent' => 0]));

        // Connecting already pulled the data; a manual sync updates instead of duplicating.
        $this->assertSame(3, \App\Models\MetaAdInsight::count());
        $this->postJson('/api/meta-ads/sync')->assertOk()->assertJson(['rows' => 3]);
        $this->assertSame(180000, (int) AdSpend::where('platform', 'meta')->where('synced', true)->sum('amount'));
        $this->assertSame(3, AdSpend::count());

        foreach ([[300000, 'serum-retarget-nov', 'pending'], [200000, 'Serum-Retarget-Nov', 'completed'], [99000, 'c2', 'completed'], [50000, 'Lainnya', 'completed'], [500000, 'Serum-Retarget-Nov', 'cancelled']] as [$total, $camp, $st]) {
            $o = new Order(['code' => 'O'.$total.$st, 'customer_name' => 'A', 'phone' => '08', 'total' => $total, 'subtotal' => $total, 'status' => $st, 'utm_source' => 'facebook', 'utm_campaign' => $camp]);
            $o->user_id = $this->user->id;
            $o->save();
        }

        $r = $this->getJson('/api/meta-ads/report')->assertOk()->json();
        $this->assertSame(180000, $r['totals']['spend']);
        $c1 = collect($r['campaigns'])->firstWhere('id', 'c1');
        $this->assertSame([150000, 2, 500000, 3.33, 3, 450000], [$c1['spend'], $c1['orders'], $c1['revenue'], $c1['roas'], $c1['meta_purchases'], $c1['meta_value']]);
        $c2 = collect($r['campaigns'])->firstWhere('id', 'c2');
        $this->assertSame([1, 99000, 12], [$c2['orders'], $c2['revenue'], $c2['messages']]); // matched by campaign id
        $this->assertSame(1, $r['unmatched']['orders']);
        // Reach is the period's unique reach from Meta, results follow Ads Manager's "Hasil".
        $this->assertSame([4500, 80], [$r['totals']['reach'], $r['totals']['results']]);
        $this->assertSame([4100, 80, 'Tayangan Halaman Landas', 1875], [$c1['reach'], $c1['results'], $c1['result_label'], $c1['cost_per_result']]);

        $this->deleteJson('/api/meta-ads')->assertNoContent();
        Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_contains($req->url(), 'me/permissions'));
        $this->assertSame(0, UserIntegration::count());
    }

    public function test_bad_token_is_rejected(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400)]);
        $this->postJson('/api/meta-ads/token', ['access_token' => str_repeat('y', 40)])->assertStatus(422)->assertJsonFragment(['message' => 'Akses Meta kedaluwarsa atau dicabut. Hubungkan ulang akun Meta.']);
        $this->assertSame(0, UserIntegration::count());
    }

    public function test_empty_account_selection_still_syncs_active_accounts(): void
    {
        $this->fakeGraph();
        $this->postJson('/api/meta-ads/token', ['access_token' => str_repeat('x', 40)])->assertOk();
        $i = UserIntegration::sole();
        $i->forceFill(['settings' => $i->settings + ['account_ids' => []]])->save();
        $this->postJson('/api/meta-ads/sync')->assertOk()->assertJson(['rows' => 3]);
        $this->assertNull($this->getJson('/api/meta-ads')->json('selected'));
    }
}
