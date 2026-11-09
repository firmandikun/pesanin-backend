<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use App\Models\UserIntegration;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** WhatsApp Business Cloud API: connect, webhook in, inbox, read receipts, reply (faked Graph API). */
class WhatsAppChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Http::fake([
            'graph.facebook.com/*/111222333?*' => Http::response(['display_phone_number' => '+62 812-0000-1111', 'verified_name' => 'Toko Yurizumi', 'quality_rating' => 'GREEN', 'id' => '111222333']),
            'graph.facebook.com/*/111222333/messages' => fn ($r) => Http::response(isset($r['status']) ? ['success' => true] : ['messages' => [['id' => 'wamid.OUT1']]]),
        ]);

        // Connect from Settings → Integrasi
        $res = $this->putJson('/api/integrations/whatsapp_cloud', ['phone_number_id' => '111222333', 'access_token' => 'EAAtoken', 'app_secret' => 'shh'])->assertOk()->json();
        $i = UserIntegration::sole();
        $this->assertSame('+62 812-0000-1111', $i->settings['display_phone']);
        $wa = collect($this->getJson('/api/integrations')->json())->get('whatsapp_cloud');
        $this->assertStringEndsWith('/api/whatsapp/webhook/'.$i->webhook_token, $wa['webhook_url']);

        // Meta's subscription check
        $this->get("/api/whatsapp/webhook/{$i->webhook_token}?hub.mode=subscribe&hub.verify_token={$i->webhook_token}&hub.challenge=42")->assertOk()->assertSee('42');
        $this->get("/api/whatsapp/webhook/{$i->webhook_token}?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42")->assertForbidden();

        // Incoming message, signed with the App Secret
        $payload = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '111222333'],
            'contacts' => [['wa_id' => '6281234', 'profile' => ['name' => 'Rina']]],
            'messages' => [['id' => 'wamid.IN1', 'from' => '6281234', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Kak ready?']]],
        ]]]]]]);
        $sig = 'sha256='.hash_hmac('sha256', $payload, 'shh');
        $this->call('POST', "/api/whatsapp/webhook/{$i->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $payload)->assertOk();
        $this->call('POST', "/api/whatsapp/webhook/{$i->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $payload)->assertOk(); // retry: no duplicate
        $this->call('POST', "/api/whatsapp/webhook/{$i->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $payload)->assertForbidden();
        $this->assertSame(1, WaMessage::count());
        $this->assertSame(['Pesan WhatsApp baru', '/chat?channel=whatsapp&source=111222333&c=6281234'], [AppNotification::sole()->title, AppNotification::sole()->url]);

        // Inbox
        $chat = $this->getJson('/api/chat')->assertOk()->json();
        $c = collect($chat['conversations'])->firstWhere('channel', 'whatsapp');
        $this->assertSame(['Rina', '6281234', 'Kak ready?', 1, '+62 812-0000-1111'], [$c['name'], $c['to_id'], $c['last_text'], $c['unread'], $c['source_name']]);
        $this->assertTrue(collect($chat['channels'])->firstWhere('id', 'whatsapp')['available']);

        // Open → read + blue ticks; reply
        $this->getJson('/api/chat/whatsapp/111222333/6281234')->assertOk()->assertJsonPath('messages.0.text', 'Kak ready?');
        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages') && ($r['status'] ?? null) === 'read' && $r['message_id'] === 'wamid.IN1');
        $this->assertSame(0, collect($this->getJson('/api/chat')->json('conversations'))->firstWhere('channel', 'whatsapp')['unread']);
        $this->postJson('/api/chat/whatsapp/111222333', ['to_id' => '6281234', 'text' => 'Ready kak'])->assertOk()->assertJsonPath('message.id', 'wamid.OUT1');
        Http::assertSent(fn ($r) => str_contains($r->url(), '111222333/messages') && ($r['text']['body'] ?? null) === 'Ready kak' && $r['to'] === '6281234');

        // Delivery status from webhook
        $st = json_encode(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '111222333'], 'statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']]]]]]]]);
        $this->call('POST', "/api/whatsapp/webhook/{$i->webhook_token}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $st, 'shh')], $st)->assertOk();
        $this->assertSame('read', WaMessage::where('message_id', 'wamid.OUT1')->value('status'));

        // Another seller's chat is not reachable
        $this->getJson('/api/chat/whatsapp/999/6281234')->assertNotFound();
    }
}
