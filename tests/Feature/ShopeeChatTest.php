<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Chat Shopee inbox over v2.sellerchat (faked), including the "not available" state. */
class ShopeeChatTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 777, 'shop_name' => 'Toko A', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $user->id;
        $this->shop->save();
    }

    public function test_inbox_messages_and_send(): void
    {
        Http::fake([
            '*get_conversation_list*' => Http::response(['response' => ['conversations' => [
                ['conversation_id' => '11', 'to_id' => 501, 'to_name' => 'rina', 'unread_count' => 2, 'pinned' => false, 'latest_message_type' => 'text', 'latest_message_content' => ['text' => 'ready kak?'], 'latest_message_from_id' => 501, 'last_message_timestamp' => 1794000000000000000],
                ['conversation_id' => '12', 'to_id' => 502, 'to_name' => 'budi', 'unread_count' => 0, 'pinned' => true, 'latest_message_type' => 'image', 'latest_message_content' => [], 'last_message_timestamp' => 1793000000000000000],
            ]]]),
            '*get_message*' => Http::response(['response' => ['messages' => [
                ['message_id' => '2', 'from_shop_id' => 777, 'message_type' => 'text', 'content' => ['text' => 'ready'], 'created_timestamp' => 1794000100],
                ['message_id' => '1', 'from_shop_id' => 0, 'message_type' => 'text', 'content' => ['text' => 'ready kak?'], 'created_timestamp' => 1794000000],
            ]]]),
            '*read_conversation*' => Http::response(['response' => []]),
            '*send_message*' => Http::response(['response' => ['message_id' => '3']]),
        ]);

        $r = $this->getJson('/api/chat')->assertOk()->json();
        $this->assertTrue($r['available']);
        $this->assertSame(['budi', 'rina'], array_column($r['conversations'], 'name')); // pinned first
        $this->assertSame('[Gambar]', $r['conversations'][0]['last_text']);
        $this->assertSame(2, $r['unread']);
        $this->assertSame(['shopee', 'Toko A'], [$r['conversations'][0]['channel'], $r['conversations'][0]['source_name']]);
        $this->assertSame(['shopee', 'facebook', 'instagram', 'whatsapp'], array_column($r['channels'], 'id'));
        $this->assertSame([], $this->getJson('/api/chat?channel=facebook')->json('conversations'));

        $m = $this->getJson("/api/chat/shopee/{$this->shop->id}/11")->assertOk()->json('messages');
        $this->assertSame([[false, 'ready kak?'], [true, 'ready']], array_map(fn ($x) => [$x['from_me'], $x['text']], $m));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'read_conversation') && $req['last_read_message_id'] === '2');

        $this->postJson("/api/chat/shopee/{$this->shop->id}", ['to_id' => 501, 'text' => 'Ready kak'])->assertOk()->assertJsonPath('message.id', '3');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'send_message') && $req['to_id'] === 501 && $req['content']['text'] === 'Ready kak');
    }

    public function test_reports_unavailable_when_shopee_refuses(): void
    {
        Http::fake(['*' => Http::response(['error' => 'error_permission', 'message' => 'No permission'], 403)]);
        $r = $this->getJson('/api/chat')->assertOk()->json();
        $this->assertFalse($r['available']);
        $this->assertStringContainsString('Toko A', $r['reason']);
    }

    public function test_new_buyer_chat_creates_a_notification(): void
    {
        $conv = fn ($msgId, $from, $unread) => ['conversation_id' => '11', 'to_id' => 501, 'to_name' => 'rina', 'unread_count' => $unread,
            'latest_message_id' => $msgId, 'latest_message_from_id' => $from, 'latest_message_type' => 'text', 'latest_message_content' => ['text' => 'ready kak?'], 'last_message_timestamp' => 1794000000000000000];
        $replies = [
            [$conv('m1', 501, 1)],   // already there at first check → remembered, not notified
            [$conv('m1', 501, 1)],   // nothing new
            [$conv('m2', 501, 2)],   // buyer wrote again → notify
            [$conv('m3', 777, 0)],   // the seller's own reply → no notification
        ];
        $n = 0;
        Http::fake(['*get_conversation_list*' => function () use (&$n, $replies) { return Http::response(['response' => ['conversations' => $replies[$n++]]]); }]);

        $sent = collect(range(1, 4))->map(fn () => \App\Support\Shopee\ShopeeChatWatch::check($this->shop))->all();
        $this->assertSame([0, 0, 1, 0], $sent);
        $note = \App\Models\AppNotification::sole();
        $this->assertSame(['chat', 'Chat Shopee baru'], [$note->type, $note->title]);
        $this->assertStringContainsString('rina: ready kak?', $note->body);
        $this->assertSame("/chat?channel=shopee&source={$this->shop->id}&c=11", $note->url);
    }

    public function test_watch_backs_off_when_shopee_refuses(): void
    {
        Http::fake(['*' => Http::response(['error' => 'error_permission', 'message' => 'No permission'], 403)]);
        \App\Support\Shopee\ShopeeChatWatch::check($this->shop);
        \App\Support\Shopee\ShopeeChatWatch::check($this->shop);
        Http::assertSentCount(1);
    }
}
