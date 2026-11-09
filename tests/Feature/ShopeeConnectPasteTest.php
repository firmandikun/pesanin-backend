<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Connecting a Shopee shop by pasting the redirect URL (when the callback can't be reached). */
class ShopeeConnectPasteTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasted_redirect_url_connects_the_shop(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);
        Http::fake([
            '*/api/v2/auth/token/get*' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expire_in' => 14400]),
            '*/api/v2/shop/get_shop_info*' => Http::response(['shop_name' => 'Toko Baru', 'region' => 'ID']),
            '*' => Http::response([]),
        ]);
        $state = fn ($uid) => urlencode(Crypt::encryptString(json_encode(['uid' => $uid, 'exp' => now()->addMinutes(15)->timestamp])));

        $this->postJson('/api/marketplace/shopee/complete', ['url' => 'https://dead.example/api/shopee/callback?foo=1'])->assertStatus(422);
        $this->postJson('/api/marketplace/shopee/complete', ['url' => "https://dead.example/api/shopee/callback?state={$state($other->id)}&code=c&shop_id=555"])->assertStatus(422);

        $this->postJson('/api/marketplace/shopee/complete', ['url' => "https://dead.example/api/shopee/callback?state={$state($user->id)}&code=abc&shop_id=555"])
            ->assertOk()->assertJsonPath('shop.shop_id', 555);
        $shop = MarketplaceShop::where('user_id', $user->id)->first();
        $this->assertSame(['active', 'AT'], [$shop->status, $shop->access_token]);
    }
}
