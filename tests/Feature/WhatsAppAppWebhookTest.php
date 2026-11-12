<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIntegration;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Production webhook: one URL for all sellers, signed by the Meta app, routed by phone number id. */
class WhatsAppAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.verify_token' => 'verif-123', 'services.meta.app_secret' => 'appsecret']);
    }

    public function test_verify_challenge(): void
    {
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=salah&hub_challenge=42')->assertForbidden();
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=verif-123&hub_challenge=42')->assertOk()->assertSee('42');
    }

    public function test_messages_are_routed_to_the_seller_owning_the_number(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        foreach ([[$a, '111'], [$b, '222']] as [$u, $pn]) {
            UserIntegration::create(['user_id' => $u->id, 'provider' => 'whatsapp_cloud', 'webhook_token' => 'tok'.$pn, 'status' => 'active', 'credentials' => ['access_token' => 'x'], 'settings' => ['phone_number_id' => $pn]]);
        }
        $body = json_encode(['entry' => [['changes' => [
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '222'], 'contacts' => [['wa_id' => '6281', 'profile' => ['name' => 'Rina']]],
                'messages' => [['id' => 'wamid.1', 'from' => '6281', 'type' => 'text', 'text' => ['body' => 'halo'], 'timestamp' => time()]]]],
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999'], 'messages' => [['id' => 'wamid.2', 'from' => '6282', 'type' => 'text', 'text' => ['body' => 'x']]]]],
        ]]]]);
        $post = fn ($sig) => $this->call('POST', '/api/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], $body);

        $post('sha256=bad')->assertForbidden();
        $post('sha256='.hash_hmac('sha256', $body, 'appsecret'))->assertOk();
        $this->assertSame($b->id, WaMessage::where('message_id', 'wamid.1')->value('user_id'));
        $this->assertFalse(WaMessage::where('message_id', 'wamid.2')->exists());
    }
}
