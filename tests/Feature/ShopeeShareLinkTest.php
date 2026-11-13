<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The shop owner authorises Shopee on their own laptop: no Pesanin login needed on that device. */
class ShopeeShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k', 'services.frontend_url' => 'https://app.test', 'app.url' => 'https://app.test']);
    }

    public function test_share_link_opens_shopee_authorisation_for_the_account(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $url = $this->postJson('/api/marketplace/shopee/share-link')->assertOk()->json('url');
        $this->assertStringStartsWith('https://app.test/api/shopee/authorize?t=', $url);

        $res = $this->get('/api/shopee/authorize?'.parse_url($url, PHP_URL_QUERY))->assertRedirect();
        $this->assertStringContainsString('/api/v2/shop/auth_partner', $res->headers->get('Location'));

        $this->get('/api/shopee/authorize?t=garbage')->assertRedirect('https://app.test/shopee-terhubung?error='.urlencode('Link tidak valid. Minta link baru ke pemilik akun Pesanin.'));
        $expired = Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => time() - 1, 'share' => true]));
        $this->assertStringContainsString('kedaluwarsa', urldecode($this->get('/api/shopee/authorize?t='.urlencode($expired))->headers->get('Location')));
    }

    public function test_callback_lands_on_a_public_confirmation_page(): void
    {
        $user = User::factory()->create(['store_name' => 'Rina Beauty']);
        Http::fake([
            '*/api/v2/auth/token/get*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expire_in' => 14400]),
            '*/api/v2/shop/get_shop_info*' => Http::response(['shop_name' => 'M storeku', 'region' => 'ID']),
            '*' => Http::response(['response' => []]),
        ]);
        $state = urlencode(Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->addMinutes(15)->timestamp])));

        $loc = $this->get("/api/shopee/callback?state={$state}&code=abc&shop_id=777")->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://app.test/shopee-terhubung?', $loc);
        parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
        $this->assertSame(['M storeku', 'Rina Beauty'], [$q['shop'], $q['store']]);
        $this->assertSame($user->id, MarketplaceShop::where('shop_id', 777)->value('user_id'));
    }
}
