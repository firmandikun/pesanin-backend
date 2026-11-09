<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\Customer;
use App\Models\User;
use App\Models\UserIntegration;
use App\Models\WaMessage;
use App\Support\WhatsApp\BroadcastSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** WhatsApp broadcast: templates from Meta, audience, quota, paced sending and delivery statuses. */
class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserIntegration $wa;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.graph_version' => 'v27.0', 'services.meta.app_id' => '1', 'services.meta.app_secret' => 's']);
        $this->user = User::factory()->create(['plan' => 'starter', 'plan_expires_at' => now()->addMonth()]);
        Sanctum::actingAs($this->user);
        $this->wa = UserIntegration::create(['user_id' => $this->user->id, 'provider' => 'whatsapp_cloud', 'webhook_token' => 'tok', 'status' => 'active',
            'credentials' => ['access_token' => 'EAAT'], 'settings' => ['phone_number_id' => '555', 'waba_id' => '999', 'display_phone' => '+1 555']]);
        foreach ([['Rina', '081234567890', true, ['VIP']], ['Budi', '0812-1111-2222', true, []], ['Sari', '081299998888', false, ['VIP']], ['Tanpa HP', null, true, []]] as [$n, $p, $opt, $l]) {
            $c = new Customer(['name' => $n, 'phone' => Customer::normalizePhone($p), 'opt_in' => $opt, 'labels' => $l]);
            $c->user_id = $this->user->id;
            $c->save();
        }
    }

    private function fakeMeta(array $extra = []): void
    {
        Http::fake($extra + [
            'graph.facebook.com/v27.0/999/message_templates*' => Http::response(['data' => [
                ['id' => 't1', 'name' => 'promo_bulan_ini', 'language' => 'id', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Halo {{1}}, diskon {{2}} untuk kamu!'], ['type' => 'FOOTER', 'text' => 'Toko Demo']]],
                ['id' => 't2', 'name' => 'katalog_gambar', 'language' => 'id', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'Lihat katalog']]],
                ['id' => 't3', 'name' => 'menunggu', 'language' => 'id', 'status' => 'PENDING', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hai']]],
            ]]),
            'graph.facebook.com/v27.0/555/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.A']], 'contacts' => [['wa_id' => '6281234567890']]])
                ->push(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)
                ->push(['messages' => [['id' => 'wamid.C']], 'contacts' => [['wa_id' => '6281300000000']]]),
            'graph.facebook.com/v27.0/555*' => Http::response(['messaging_limit_tier' => 'TIER_1K', 'quality_rating' => 'GREEN']),
        ]);
    }

    public function test_templates_are_listed_with_support_flags(): void
    {
        $this->fakeMeta();
        $t = collect($this->getJson('/api/whatsapp/templates')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(2, $t['promo_bulan_ini']['variables']);
        $this->assertTrue($t['promo_bulan_ini']['supported']);
        $this->assertFalse($t['katalog_gambar']['supported']);
    }

    public function test_create_template_validates_and_sends_example(): void
    {
        $this->fakeMeta(['graph.facebook.com/v27.0/999/message_templates' => Http::response(['id' => 'new', 'status' => 'PENDING'])]);
        $this->postJson('/api/whatsapp/templates', ['name' => 'Bad Name', 'language' => 'id', 'category' => 'MARKETING', 'body' => 'x'])->assertStatus(422);
        $this->postJson('/api/whatsapp/templates', ['name' => 'ok', 'language' => 'id', 'category' => 'MARKETING', 'body' => 'Halo {{2}} kak'])->assertStatus(422);
        $this->postJson('/api/whatsapp/templates', ['name' => 'sapa', 'language' => 'id', 'category' => 'UTILITY', 'body' => 'Halo {{1}}, pesanan siap', 'examples' => ['Rina'], 'buttons' => ['Oke']])
            ->assertOk()->assertJsonPath('status', 'PENDING');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/999/message_templates')
            && $r['components'][0]['example']['body_text'] === [['Rina']] && $r['components'][1]['buttons'][0]['type'] === 'QUICK_REPLY');
    }

    public function test_broadcast_audience_quota_send_and_statuses(): void
    {
        $this->fakeMeta();
        // Opted-in customers with a phone and label VIP → only Rina; all opted-in → Rina + Budi.
        $this->postJson('/api/broadcasts/preview', ['audience' => ['source' => 'customers', 'label' => 'VIP', 'opt_in_only' => true]])->assertJsonPath('count', 1);
        $this->postJson('/api/broadcasts/preview', ['audience' => ['source' => 'manual', 'numbers' => "0812 1 / x\n081300000000, Andi\n+62 813-0000-0000"]])->assertJsonPath('count', 1);

        $tpl = ['name' => 'promo_bulan_ini', 'language' => 'id'];
        $this->postJson('/api/broadcasts', ['name' => 'Promo', 'template' => ['name' => 'menunggu', 'language' => 'id'], 'audience' => ['source' => 'customers']])->assertStatus(422);
        $this->postJson('/api/broadcasts', ['name' => 'Promo', 'template' => $tpl, 'variables' => [['mode' => 'name']], 'audience' => ['source' => 'customers']])->assertStatus(422);

        $b = $this->postJson('/api/broadcasts', [
            'name' => 'Promo November', 'template' => $tpl,
            'variables' => [['mode' => 'name', 'value' => 'Kak'], ['mode' => 'text', 'value' => '20%']],
            'audience' => ['source' => 'manual', 'numbers' => "081234567890,Rina\n081211112222\n081300000000,Andi"],
        ])->assertCreated()->json('broadcast');
        $this->assertSame(3, $b['total']);

        BroadcastSender::run(10);
        $show = $this->getJson("/api/broadcasts/{$b['id']}")->assertOk();
        $show->assertJsonPath('broadcast.status', 'done')->assertJsonPath('broadcast.sent', 2)->assertJsonPath('broadcast.failed', 1);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/messages') && $r['to'] === '6281234567890'
            && $r['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Rina'], ['type' => 'text', 'text' => '20%']]);
        // Unnamed number falls back to the default for the name variable.
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/messages') && ($r['to'] ?? '') === '6281211112222');
        $this->assertSame('Halo Rina, diskon 20% untuk kamu!', WaMessage::where('message_id', 'wamid.A')->value('text'));

        // Delivery statuses from the webhook, never moving backwards.
        $hook = fn ($id, $st) => $this->postJson('/api/whatsapp/webhook/tok', ['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '555'], 'statuses' => [['id' => $id, 'status' => $st]]]]]]]])->assertOk();
        $hook('wamid.A', 'read');
        $hook('wamid.A', 'delivered');
        $hook('wamid.C', 'delivered');
        $this->getJson("/api/broadcasts/{$b['id']}")->assertJsonPath('broadcast.read', 1)->assertJsonPath('broadcast.delivered', 2);

        $this->getJson('/api/broadcasts/status')->assertJsonPath('tier', 'TIER_1K')->assertJsonPath('capacity', 1000)->assertJsonPath('plan.used', 3)->assertJsonPath('used_24h', 2);
    }

    public function test_schedule_cancel_and_plan_quota(): void
    {
        $this->fakeMeta();
        $tpl = ['name' => 'promo_bulan_ini', 'language' => 'id'];
        $vars = [['mode' => 'name'], ['mode' => 'text', 'value' => '10%']];
        $b = $this->postJson('/api/broadcasts', ['name' => 'Nanti', 'template' => $tpl, 'variables' => $vars, 'audience' => ['source' => 'customers'], 'scheduled_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->json('broadcast');
        $this->assertSame(0, BroadcastSender::run());
        $this->postJson("/api/broadcasts/{$b['id']}/cancel")->assertJsonPath('broadcast.status', 'cancelled');
        $this->assertSame('cancelled', Broadcast::find($b['id'])->status);

        // Gratis plan: 100 recipients a month.
        $this->user->forceFill(['plan' => 'gratis'])->save();
        $numbers = implode("\n", array_map(fn ($i) => '0813'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), range(1, 101)));
        $this->postJson('/api/broadcasts', ['name' => 'Besar', 'template' => $tpl, 'variables' => $vars, 'audience' => ['source' => 'manual', 'numbers' => $numbers]])
            ->assertStatus(402)->assertJsonPath('limit', 'broadcast');
    }
}
