<?php

namespace Tests\Feature;

use App\Models\MarketplaceEvent;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Shopee Live Push: the controller still applies events inline; events stored by the Go receiver are applied by shopee:push-process, once. */
class ShopeePushQueueTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): MarketplaceShop
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k', 'services.shopee.push_key' => 'pushkey', 'services.shopee.push_url' => 'https://pesaninn.my.id/api/shopee/push']);
        $user = User::factory()->create();

        return MarketplaceShop::create([
            'user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 5550001, 'shop_name' => 'Glow', 'status' => 'active',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'access_expires_at' => now()->addHours(3),
        ]);
    }

    private function detailCalls(): int
    {
        return collect(Http::recorded())->filter(fn ($r) => str_contains($r[0]->url(), '/order/get_order_detail'))->count();
    }

    public function test_controller_applies_event_inline(): void
    {
        $this->shop();
        Http::fake(['*' => Http::response(['response' => ['order_list' => []]])]);
        $body = json_encode(['shop_id' => 5550001, 'code' => 3, 'data' => ['ordersn' => 'SN1', 'status' => 'READY_TO_SHIP']]);
        $sig = hash_hmac('sha256', 'https://pesaninn.my.id/api/shopee/push|'.$body, 'pushkey');

        $this->call('POST', '/api/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => $sig, 'CONTENT_TYPE' => 'application/json'], $body)->assertOk()->assertJson(['ok' => true]);

        $event = MarketplaceEvent::sole();
        $this->assertNotNull($event->processed_at);
        $this->assertSame(1, $this->detailCalls());

        // Already applied: the command leaves it alone.
        $this->artisan('shopee:push-process --seconds=5')->assertSuccessful();
        $this->assertSame(1, $this->detailCalls());
    }

    public function test_bad_signature_is_rejected(): void
    {
        $this->shop();
        $this->call('POST', '/api/shopee/push', [], [], [], ['HTTP_AUTHORIZATION' => 'nope', 'CONTENT_TYPE' => 'application/json'], '{"shop_id":5550001,"code":3}')->assertStatus(401);
        $this->assertSame(0, MarketplaceEvent::count());
    }

    public function test_command_applies_stored_events_once(): void
    {
        $shop = $this->shop();
        Http::fake(['*' => Http::response(['response' => ['order_list' => []]])]);
        // As stored by the Go receiver: payload only, not processed yet.
        $pending = MarketplaceEvent::create(['platform' => 'shopee', 'shop_id' => 5550001, 'code' => 3, 'payload' => ['shop_id' => 5550001, 'code' => 3, 'data' => ['ordersn' => 'SN2']]]);
        $revoked = MarketplaceEvent::create(['platform' => 'shopee', 'shop_id' => 5550001, 'code' => 2, 'payload' => ['shop_id' => 5550001, 'code' => 2]]);
        $old = MarketplaceEvent::create(['platform' => 'shopee', 'shop_id' => 5550001, 'code' => 3, 'payload' => ['shop_id' => 5550001, 'code' => 3, 'data' => ['ordersn' => 'OLD']]]);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('shopee:push-process --seconds=5')->assertSuccessful();

        $this->assertNotNull($pending->fresh()->processed_at);
        $this->assertNotNull($revoked->fresh()->processed_at);
        $this->assertNull($old->fresh()->processed_at, 'events older than a day are left alone');
        $this->assertSame('disconnected', $shop->fresh()->status);
        $this->assertSame(1, $this->detailCalls());

        $this->artisan('shopee:push-process --seconds=5')->assertSuccessful();
        $this->assertSame(1, $this->detailCalls(), 'each event is applied once');
    }

    public function test_failure_is_recorded_and_not_retried(): void
    {
        $this->shop();
        Http::fake(['*' => Http::response(['error' => 'error_auth', 'message' => 'Invalid access_token'], 403)]);
        $event = MarketplaceEvent::create(['platform' => 'shopee', 'shop_id' => 5550001, 'code' => 3, 'payload' => ['shop_id' => 5550001, 'code' => 3, 'data' => ['ordersn' => 'SN3']]]);

        $this->artisan('shopee:push-process --seconds=5')->assertSuccessful();
        $this->assertNotNull($event->fresh()->error);

        $calls = count(Http::recorded());
        $this->artisan('shopee:push-process --seconds=5')->assertSuccessful();
        $this->assertSame($calls, count(Http::recorded()));
    }
}
