<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MarketplaceShop;
use App\Models\ShopeeReview;
use App\Models\User;
use App\Support\Shopee\ShopeeHealth;
use App\Support\Shopee\ShopeeReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Kesehatan akun Shopee (account_health) and Ulasan (get_comment / reply_comment). */
class ShopeeHealthReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    private array $replied = [];

    private array $penalties = [['reference_id' => 11, 'issue_time' => 1763500000, 'latest_point_num' => 1, 'original_point_num' => 1, 'violation_type' => 5]];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 5, 'shop_name' => 'M storeku', 'status' => 'active', 'is_demo' => false, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $this->user->id;
        $this->shop->save();
        Http::fake(function ($req) {
            $u = $req->url();

            return match (true) {
                str_contains($u, 'get_shop_performance') => Http::response(['response' => [
                    'overall_performance' => ['rating' => 2, 'fulfillment_failed' => 1, 'listing_failed' => 0, 'custom_service_failed' => 0],
                    'metric_list' => [
                        ['metric_type' => 1, 'metric_id' => 1, 'metric_name' => 'Late Shipment Rate', 'current_period' => 4.5, 'last_period' => 2, 'unit' => 2, 'target' => ['value' => 3, 'comparator' => '<']],
                        ['metric_type' => 3, 'metric_id' => 11, 'metric_name' => 'Chat Response Rate', 'current_period' => 95, 'unit' => 2, 'target' => ['value' => 70, 'comparator' => '>=']],
                    ]]]),
                str_contains($u, 'get_penalty_point_history') => Http::response(['response' => ['penalty_point_list' => $this->penalties, 'total_count' => count($this->penalties)]]),
                str_contains($u, 'get_late_orders') => Http::response(['response' => ['late_order_list' => [['order_sn' => 'LATE1', 'shipping_deadline' => 1763400000, 'late_by_days' => 2]], 'total_count' => 1]]),
                str_contains($u, 'get_listings_with_issues') => Http::response(['response' => ['listing_list' => [['item_id' => 77, 'reason' => 5]], 'total_count' => 1]]),
                str_contains($u, 'get_comment') => Http::response(['response' => ['more' => false, 'next_cursor' => '', 'item_comment_list' => [
                    ['comment_id' => 901, 'item_id' => 77, 'order_sn' => 'O1', 'buyer_username' => 'budi', 'rating_star' => 5, 'comment' => 'Mantap, sesuai', 'create_time' => 1763500000],
                    ['comment_id' => 902, 'item_id' => 77, 'order_sn' => 'O2', 'buyer_username' => 'sari', 'rating_star' => 2, 'comment' => 'Barang lecet', 'create_time' => 1763400000],
                    ['comment_id' => 903, 'item_id' => 77, 'order_sn' => 'O3', 'buyer_username' => 'andi', 'rating_star' => 4, 'comment' => 'Oke', 'create_time' => 1763300000,
                        'comment_reply' => ['reply' => 'Terima kasih', 'create_time' => 1763310000]],
                ]]]),
                str_contains($u, 'reply_comment') => (function () use ($req) {
                    foreach ($req['comment_list'] as $c) {
                        $this->replied[$c['comment_id']] = $c['comment'];
                    }

                    return Http::response(['response' => ['result_list' => []]]);
                })(),
                default => Http::response(['response' => []]),
            };
        });
    }

    public function test_health_page_shows_metrics_penalties_late_orders_and_listings(): void
    {
        $h = $this->getJson('/api/marketplace/health')->assertOk()->json('shops.0');
        $this->assertSame(['rating' => 2, 'label' => 'Perlu Ditingkatkan'], array_intersect_key($h['overall'], ['rating' => 0, 'label' => 0]));
        $late = collect($h['metrics'])->firstWhere('type', 'Performa Pengiriman')['metrics'][0];
        $this->assertSame(['Tingkat Keterlambatan Pengiriman', false, '%'], [$late['name'], $late['ok'], $late['unit']]);
        $this->assertTrue(collect($h['metrics'])->firstWhere('type', 'Pelayanan Pembeli')['metrics'][0]['ok']);
        $this->assertSame(1, $h['penalty']['total']);
        $this->assertSame('Tingkat keterlambatan pengiriman tinggi', $h['penalty']['records'][0]['reason']);
        $this->assertSame([['LATE1', 2]], collect($h['late_orders'])->map(fn ($o) => [$o['order_sn'], $o['late_days']])->all());
        $this->assertSame('Informasi kurang lengkap', $h['listings'][0]['reason']);
    }

    public function test_daily_health_alert_reports_each_penalty_once(): void
    {
        $this->assertSame(3, ShopeeHealth::check($this->user)); // new penalty + late orders + performance below Baik
        $this->assertStringContainsString('+1 poin penalti', AppNotification::sole()->body);
        // Next day, same penalty: not repeated (late orders / rating still reported).
        $this->assertSame(2, ShopeeHealth::check($this->user));
        $this->penalties[] = ['reference_id' => 12, 'issue_time' => 1763600000, 'latest_point_num' => 2, 'original_point_num' => 2, 'violation_type' => 21];
        $this->assertSame(3, ShopeeHealth::check($this->user));
        $this->assertStringContainsString('Banyak chat tidak dibalas', AppNotification::latest('id')->first()->body);
    }

    public function test_reviews_sync_and_stats(): void
    {
        $this->postJson('/api/marketplace/reviews/sync')->assertOk()->assertJson(['new' => 3]);
        $this->postJson('/api/marketplace/reviews/sync')->assertOk()->assertJson(['new' => 0]); // idempotent
        $r = $this->getJson('/api/marketplace/reviews?replied=no')->assertOk()->json();
        $this->assertSame(['budi', 'sari'], collect($r['reviews']['data'])->pluck('buyer')->all());
        $this->assertSame(['total' => 3, 'average' => 3.67, 'unreplied' => 2, 'low_unreplied' => 1], array_intersect_key($r['stats'], ['total' => 0, 'average' => 0, 'unreplied' => 0, 'low_unreplied' => 0]));
    }

    public function test_reply_typed_and_bulk_with_templates(): void
    {
        ShopeeReviews::sync($this->shop);
        $budi = ShopeeReview::firstWhere('comment_id', 901);
        $sari = ShopeeReview::firstWhere('comment_id', 902);
        $this->postJson('/api/marketplace/reviews/reply', ['replies' => [['id' => $sari->id, 'text' => 'Maaf kak, kami kirim pengganti ya']]])->assertOk()->assertJson(['sent' => 1]);
        $this->postJson('/api/marketplace/reviews/reply', ['ids' => [$budi->id, $sari->id]])->assertOk()->assertJson(['sent' => 1]); // sari already answered
        $this->assertSame('Maaf kak, kami kirim pengganti ya', $this->replied[902]);
        $this->assertStringContainsString('Terima kasih banyak kak budi', $this->replied[901]); // 5★ template with {nama}
        $this->assertNotNull($budi->fresh()->replied_at);
    }

    public function test_hourly_run_auto_replies_good_reviews_and_alerts_low_ones(): void
    {
        $this->user->forceFill(['settings' => ['reviews' => ['auto_reply' => true, 'auto_min_rating' => 4]]])->save();
        $this->assertSame(3, ShopeeReviews::run($this->user->fresh()));
        $this->assertSame([901], array_keys($this->replied));            // 5★ yes; 4★ already replied in Shopee; 2★ never auto
        $this->assertStringContainsString('Barang lecet', AppNotification::sole()->body); // 2★ alerted
    }
}
