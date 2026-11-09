<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Connecting WhatsApp by signing in with Facebook instead of pasting a token. */
class WhatsAppConnectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.graph_version' => 'v27.0', 'services.meta.app_id' => '1', 'services.meta.app_secret' => 's', 'services.meta.wa_config_id' => '77', 'services.frontend_url' => 'http://front']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function fake(array $numbers): void
    {
        Http::fake([
            '*/oauth/access_token*' => Http::sequence()->push(['access_token' => 'SHORT'])->push(['access_token' => 'LONG']),
            '*/debug_token*' => Http::response(['data' => ['expires_at' => 0, 'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['999']], ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['999']]]]]),
            '*/999/phone_numbers*' => Http::response(['data' => $numbers]),
            '*/999/subscribed_apps' => Http::response(['success' => true]),
        ]);
    }

    private function loginCallback(): \Illuminate\Testing\TestResponse
    {
        $this->getJson('/api/whatsapp/connect')->assertStatus(405);
        $url = $this->postJson('/api/whatsapp/connect')->assertOk()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('77', $q['config_id']);

        return $this->get('/api/meta/callback?'.http_build_query(['code' => 'abc', 'state' => $q['state']]));
    }

    public function test_single_number_connects_directly(): void
    {
        $this->fake([['id' => '555', 'display_phone_number' => '+62 812-0000-1111', 'verified_name' => 'Toko A', 'quality_rating' => 'GREEN']]);
        $this->loginCallback()->assertRedirect('http://front/settings/integrations?wa=connected#whatsapp');

        $i = UserIntegration::where('user_id', $this->user->id)->where('provider', 'whatsapp_cloud')->first();
        $this->assertSame('LONG', $i->key('access_token'));
        $this->assertSame(['555', '999', 'login'], [$i->settings['phone_number_id'], $i->settings['waba_id'], $i->settings['connected_via']]);
        $this->assertNotEmpty($i->webhook_token);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/999/subscribed_apps'));
    }

    public function test_several_numbers_let_the_seller_pick(): void
    {
        $this->fake([['id' => '555', 'display_phone_number' => '+62 1'], ['id' => '556', 'display_phone_number' => '+62 2']]);
        $this->loginCallback()->assertRedirect('http://front/settings/integrations?wa=pick#whatsapp');
        $this->getJson('/api/whatsapp/connect/pending')->assertJsonCount(2, 'numbers');
        $this->postJson('/api/whatsapp/connect/select', ['phone_number_id' => 'nope'])->assertStatus(422);
        $this->postJson('/api/whatsapp/connect/select', ['phone_number_id' => '556'])->assertJsonPath('phone', '+62 2');
        $this->assertSame('556', UserIntegration::where('provider', 'whatsapp_cloud')->first()->settings['phone_number_id']);
        $this->getJson('/api/whatsapp/connect/pending')->assertJsonCount(0, 'numbers');
    }

    public function test_login_without_numbers_shows_error(): void
    {
        $this->fake([]);
        $this->loginCallback()->assertRedirectContains('wa_error=');
        $this->assertSame(0, UserIntegration::count());
    }
}
