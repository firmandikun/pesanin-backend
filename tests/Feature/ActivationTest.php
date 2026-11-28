<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Activation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Aktivasi seller baru: dashboard checklist, Admin Console funnel, reminders to sellers who stopped halfway. */
class ActivationTest extends TestCase
{
    use RefreshDatabase;

    private function seller(int $daysOld = 3, string $track = 'marketplace'): User
    {
        $u = User::factory()->create(['plan_track' => $track, 'whatsapp' => '081234567890']);
        $u->forceFill(['created_at' => now()->subDays($daysOld)])->save();

        return $u->fresh();
    }

    private function connect(User $u, bool $hpp = false, bool $awb = false, bool $telegram = false): void
    {
        $shop = MarketplaceShop::create(['user_id' => $u->id, 'platform' => 'shopee', 'shop_id' => random_int(1, 999999), 'shop_name' => 'T', 'status' => 'active']);
        foreach (range(1, 3) as $i) {
            MarketplaceListing::create(['user_id' => $u->id, 'marketplace_shop_id' => $shop->id, 'item_id' => $i, 'model_id' => 0, 'name' => "P{$i}", 'price' => 10000, 'stock' => 1, 'cost_price' => $hpp ? 5000 : null]);
        }
        $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => Str::random(8), 'external_status' => 'PROCESSED',
            'customer_name' => 'B', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1, 'payment_method' => 'marketplace', 'payment_status' => 'paid',
            'status' => 'processing', 'shipping_status' => 'not_shipped', 'awb_printed_at' => $awb ? now() : null]);
        $o->user_id = $u->id;
        $o->save();
        if ($telegram) {
            UserIntegration::create(['user_id' => $u->id, 'provider' => 'telegram', 'webhook_token' => Str::random(40), 'status' => 'active',
                'credentials' => ['bot_token' => '1:x'], 'settings' => ['chat_id' => '9']]);
        }
    }

    public function test_marketplace_steps_follow_real_data(): void
    {
        $u = $this->seller();
        $this->assertSame(['shop' => false, 'order' => false, 'hpp' => false, 'profit' => false, 'awb' => false, 'telegram' => false, 'whatsapp' => true], Activation::progress($u));

        $this->connect($u, hpp: true, awb: true, telegram: true);
        $s = Activation::steps($u->fresh());
        $this->assertSame([7, 7, null], [$s['done'], $s['total'], $s['next']]);

        // Dashboard exposes the same steps.
        Sanctum::actingAs($u);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('onboarding_marketplace.awb', true)->assertJsonPath('onboarding_marketplace.telegram', true);
    }

    public function test_admin_funnel_and_stuck_sellers(): void
    {
        $a = $this->seller(2);                       // signed up only
        $b = $this->seller(5);
        $this->connect($b);                         // shop + order, no HPP
        $c = $this->seller(1);
        $this->connect($c, hpp: true, awb: true, telegram: true); // complete
        $this->seller(3, 'social');                 // other track
        $this->seller(40);                          // outside the period

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);
        $r = $this->getJson('/api/admin/activation?days=30')->assertOk()->json();

        $this->assertSame([3, 1], [$r['signups'], $r['complete']]);
        $this->assertSame(['shop' => 2, 'order' => 2, 'hpp' => 1, 'profit' => 1, 'awb' => 1, 'telegram' => 1, 'whatsapp' => 3], array_column($r['funnel'], 'users', 'key'));
        $stuck = collect($r['stuck'])->pluck('next', 'id');
        $this->assertSame(['shop', 'hpp'], [$stuck[$a->id], $stuck[$b->id]]);

        Sanctum::actingAs($a);
        $this->getJson('/api/admin/activation')->assertForbidden();
    }

    public function test_nudges_once_per_step_and_not_too_often(): void
    {
        $u = $this->seller(2);
        $this->artisan('activation:nudge')->expectsOutputToContain('terkirim: 1')->assertSuccessful();
        $n = AppNotification::where('user_id', $u->id)->sole();
        $this->assertSame('Langkah berikutnya: Hubungkan toko Shopee', $n->title);
        $this->assertSame('/marketplace/shops', $n->url);

        $this->assertNull(Activation::nudge($u), 'at most one reminder every 2 days');
        $this->travel(3)->days();
        $this->connect($u);                         // shop done; next needing action: HPP
        $this->assertSame('hpp', Activation::nudge($u->fresh()));
        $this->travel(3)->days();
        $this->assertSame('awb', Activation::nudge($u->fresh()), 'HPP was already reminded once');

        $fresh = $this->seller(0);                  // < 24 hours old: not yet
        $this->assertNull(Activation::nudge($fresh));
        $old = $this->seller(20);                   // > 14 days: stop
        $this->assertNull(Activation::nudge($old));
        $off = $this->seller(2);
        $off->update(['settings' => ['notify' => ['activation' => false]]]);
        $this->assertNull(Activation::nudge($off->fresh()));
    }
}
