<?php

namespace Tests\Feature;

use App\Http\Controllers\LegalController;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Kebijakan Privasi');
        $this->get('/terms')->assertOk()->assertSee('Ketentuan Layanan');
        $this->get('/data-deletion')->assertOk()->assertSee('Penghapusan Data');
    }

    private function signed(array $data, string $secret): string
    {
        $enc = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $payload = $enc(json_encode($data));

        return $enc(hash_hmac('sha256', $payload, $secret, true)).'.'.$payload;
    }

    public function test_meta_deletion_callback_removes_meta_data(): void
    {
        config(['services.meta.app_secret' => 'sekret']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (['meta_ads', 'meta_chat'] as $p) {
            UserIntegration::create(['user_id' => $user->id, 'provider' => $p, 'credentials' => ['access_token' => 't'], 'webhook_token' => \Illuminate\Support\Str::random(40), 'settings' => ['fb_user_id' => '999']]);
        }
        UserIntegration::create(['user_id' => $other->id, 'provider' => 'meta_ads', 'credentials' => ['access_token' => 't'], 'webhook_token' => \Illuminate\Support\Str::random(40), 'settings' => ['fb_user_id' => '111']]);

        $r = $this->post('/api/meta/data-deletion', ['signed_request' => $this->signed(['algorithm' => 'HMAC-SHA256', 'user_id' => '999'], 'sekret')])
            ->assertOk()->assertJsonStructure(['url', 'confirmation_code']);

        $this->assertSame(0, UserIntegration::where('user_id', $user->id)->count());
        $this->assertSame(1, UserIntegration::where('user_id', $other->id)->count());
        $this->get('/data-deletion?code='.$r->json('confirmation_code'))->assertOk()->assertSee('Permintaan penghapusan selesai');
    }

    public function test_meta_deletion_rejects_bad_signature(): void
    {
        config(['services.meta.app_secret' => 'sekret']);
        $this->post('/api/meta/data-deletion', ['signed_request' => $this->signed(['algorithm' => 'HMAC-SHA256', 'user_id' => '1'], 'salah')])->assertStatus(400);
        $this->assertNull(LegalController::parseSignedRequest('garbage', 'sekret'));
    }
}
