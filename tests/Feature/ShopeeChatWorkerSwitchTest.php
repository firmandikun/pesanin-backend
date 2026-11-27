<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SHOPEE_CHAT_WORKER=go hands Shopee chat (notifications + auto-replies) to the Go shopee-worker. */
class ShopeeChatWorkerSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): MarketplaceShop
    {
        $user = User::factory()->create(['settings' => ['shopee_chat_automation' => ['keywords_enabled' => true, 'keywords' => [['words' => 'resi', 'text' => 'Resi {resi}']]]]]);

        return MarketplaceShop::create([
            'user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 5550001, 'shop_name' => 'Glow', 'status' => 'active',
            'access_token' => 'tok', 'refresh_token' => 'ref', 'access_expires_at' => now()->addHours(3),
        ]);
    }

    public function test_go_mode_skips_chat_in_laravel(): void
    {
        config(['services.shopee.chat_worker' => 'go', 'services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->shop();
        Http::fake(['*' => Http::response(['response' => ['order_list' => [], 'conversations' => [], 'more' => false]])]);

        $this->artisan('shopee:chat-auto')->expectsOutput('Chat otomatis dijalankan oleh shopee-worker (Go).')->assertSuccessful();
        $this->artisan('marketplace:sync', ['--what' => 'recent'])->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/sellerchat/'));
    }

    public function test_default_mode_still_checks_chat_in_laravel(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->assertSame('laravel', config('services.shopee.chat_worker'));
        $this->shop();
        Http::fake(['*' => Http::response(['response' => ['order_list' => [], 'conversations' => [], 'more' => false]])]);

        $this->artisan('marketplace:sync', ['--what' => 'recent'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/sellerchat/get_conversation_list'));
    }
}
