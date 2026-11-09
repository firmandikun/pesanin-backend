<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Inbox collaboration (assign, status, labels, notes), quick replies and customers linked to orders. */
class InboxCrmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function key(array $extra = []): array
    {
        return ['channel' => 'shopee', 'source' => '1', 'conversation' => '11', 'name' => 'rina'] + $extra;
    }

    private function order(string $phone, int $total, string $status = 'completed'): void
    {
        $o = new Order(['code' => 'O'.uniqid(), 'customer_name' => 'Rina', 'phone' => $phone, 'total' => $total, 'subtotal' => $total, 'status' => $status]);
        $o->user_id = $this->user->id;
        $o->save();
    }

    public function test_assign_label_done_and_notes(): void
    {
        $cs = new TeamMember(['name' => 'Sari', 'role' => 'cs']);
        $cs->user_id = $this->user->id;
        $cs->save();

        $this->putJson('/api/chat/thread', $this->key(['assigned_to' => $cs->id, 'labels' => ['VIP', 'VIP', 'Komplain']]))
            ->assertOk()->assertJsonPath('thread.assigned_to', $cs->id)->assertJsonPath('thread.labels', ['VIP', 'Komplain']);
        $this->getJson('/api/chat-labels')->assertJsonCount(2, 'data');

        $this->putJson('/api/chat/thread', $this->key(['status' => 'done']))->assertJsonPath('thread.status', 'done');
        $note = $this->postJson('/api/chat/thread/notes', $this->key(['body' => 'Minta diskon ongkir']))->assertOk()->json('note');
        $this->getJson('/api/chat/thread?'.http_build_query($this->key()))
            ->assertJsonPath('thread.status', 'done')->assertJsonPath('notes.0.body', 'Minta diskon ongkir');
        $this->deleteJson("/api/chat/notes/{$note['id']}")->assertNoContent();

        // Another seller's team member cannot be assigned.
        $other = new TeamMember(['name' => 'X', 'role' => 'cs']);
        $other->user_id = User::factory()->create()->id;
        $other->save();
        $this->putJson('/api/chat/thread', $this->key(['assigned_to' => $other->id]))->assertStatus(422);

        // Deleting a label removes it from chats.
        $vip = $this->getJson('/api/chat-labels')->json('data.1');
        $this->deleteJson("/api/chat-labels/{$vip['id']}")->assertNoContent();
        $this->getJson('/api/chat/thread?'.http_build_query($this->key()))->assertJsonPath('thread.labels', ['Komplain']);
    }

    public function test_inbox_rows_carry_thread_meta_and_reopen_on_new_buyer_message(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 777, 'shop_name' => 'Toko A', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $this->user->id;
        $shop->save();
        Http::fake(['*get_conversation_list*' => Http::response(['response' => ['conversations' => [
            ['conversation_id' => '11', 'to_id' => 501, 'to_name' => 'rina', 'unread_count' => 1, 'latest_message_type' => 'text', 'latest_message_content' => ['text' => 'halo lagi'], 'latest_message_from_id' => 501, 'last_message_timestamp' => now()->timestamp],
        ]]])]);

        $key = ['channel' => 'shopee', 'source' => (string) $shop->id, 'conversation' => '11'];
        $this->putJson('/api/chat/thread', $key + ['labels' => ['VIP'], 'status' => 'done'])->assertOk();
        // Done earlier than the buyer's latest message → shown as open again.
        \App\Models\ChatThread::query()->update(['done_at' => now()->subHour()]);

        $r = $this->getJson('/api/chat')->assertOk();
        $r->assertJsonPath('conversations.0.labels', ['VIP'])->assertJsonPath('conversations.0.status', 'open')->assertJsonPath('labels.0.name', 'VIP');
        $this->assertArrayHasKey('team', $r->json());
    }

    public function test_customer_from_chat_matches_orders_by_phone(): void
    {
        $this->order('0812-3456-789', 150000);
        $this->order('+62812345 6789', 50000, 'cancelled');
        $this->order('0899', 99000);

        $c = $this->postJson('/api/chat/thread/customer', $this->key(['customer' => ['name' => 'Rina', 'phone' => '6281234567 89']]))
            ->assertOk()->json('customer');
        $this->assertSame(2, $c['orders_count']);
        $this->assertSame(150000, $c['spent']);
        $this->assertCount(2, $c['orders']);

        // A WhatsApp chat with the same number is suggested this customer.
        $this->getJson('/api/chat/thread?'.http_build_query(['channel' => 'whatsapp', 'source' => 'p', 'conversation' => '6281234567 89', 'phone' => '6281234567 89']))
            ->assertJsonPath('suggest.id', $c['id']);

        $this->getJson('/api/customers?q=0812')->assertJsonPath('total', 1)->assertJsonPath('data.0.orders_count', 2);
        $this->getJson("/api/customers/{$c['id']}")->assertJsonPath('customer.chats.0.id', '11');
        $this->putJson("/api/customers/{$c['id']}", ['name' => 'Rina K', 'labels' => ['Reseller'], 'opt_in' => true])->assertJsonPath('customer.labels', ['Reseller']);
        $this->getJson('/api/customers?label=Reseller')->assertJsonPath('total', 1);

        $foreign = new Customer(['name' => 'X']);
        $foreign->user_id = User::factory()->create()->id;
        $foreign->save();
        $this->getJson("/api/customers/{$foreign->id}")->assertNotFound();
        $this->postJson('/api/chat/thread/customer', $this->key(['customer_id' => $foreign->id]))->assertStatus(422);
    }

    public function test_quick_replies_crud(): void
    {
        $r = $this->postJson('/api/quick-replies', ['shortcut' => 'rek', 'category' => 'Pembayaran', 'body' => 'BCA 123 a.n. Toko'])->assertOk()->json('reply');
        $this->postJson('/api/quick-replies', ['shortcut' => 'rek', 'body' => 'x'])->assertStatus(422);
        $this->postJson('/api/quick-replies', ['shortcut' => 'bad space', 'body' => 'x'])->assertStatus(422);
        $this->putJson("/api/quick-replies/{$r['id']}", ['shortcut' => 'rek', 'body' => 'BCA 999'])->assertJsonPath('reply.body', 'BCA 999');
        $this->getJson('/api/quick-replies')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/quick-replies/{$r['id']}")->assertNoContent();
    }
}
