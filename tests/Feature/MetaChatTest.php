<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MetaChatController;
use App\Models\User;
use App\Models\UserIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Facebook Messenger + Instagram Direct in the shared /chat inbox (faked Graph API). */
class MetaChatTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.meta.app_id' => '1', 'services.meta.app_secret' => 's', 'services.meta.chat_config_id' => '9']);
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'LONG']),
            'graph.facebook.com/*/me?*' => Http::response(['id' => '77', 'name' => 'Firman']),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => 'P1', 'name' => 'Toko Yurizumi', 'access_token' => 'PT1', 'instagram_business_account' => ['id' => 'IG1', 'username' => 'yurizumi.id']],
            ]]),
            'graph.facebook.com/*/P1/conversations*' => fn ($r) => Http::response(['data' => $r['platform'] === 'instagram'
                ? [['id' => 'IGC1', 'updated_time' => '2026-11-07T10:00:00+0000', 'unread_count' => 1,
                    'participants' => ['data' => [['username' => 'yurizumi.id', 'id' => 'IG1'], ['username' => 'dewi.beauty', 'id' => 'IGU9']]],
                    'messages' => ['data' => [['id' => 'm_ig', 'message' => 'Bisa COD?', 'from' => ['id' => 'IGU9'], 'created_time' => '2026-11-07T10:00:00+0000']]]]]
                : [['id' => 't_1', 'updated_time' => '2026-11-07T09:00:00+0000', 'unread_count' => 0,
                    'participants' => ['data' => [['name' => 'Andi', 'id' => 'PSID5'], ['name' => 'Toko Yurizumi', 'id' => 'P1']]],
                    'messages' => ['data' => [['id' => 'm_fb', 'message' => 'Siap kak', 'from' => ['id' => 'P1'], 'created_time' => '2026-11-07T09:00:00+0000']]]]]]),
            'graph.facebook.com/*/t_1?*' => Http::response(['messages' => ['data' => [
                ['id' => 'm2', 'message' => 'Siap kak', 'from' => ['id' => 'P1'], 'created_time' => '2026-11-07T09:00:00+0000'],
                ['id' => 'm1', 'message' => 'Ready?', 'from' => ['id' => 'PSID5'], 'created_time' => '2026-11-07T08:59:00+0000'],
            ]]]),
            'graph.facebook.com/*/P1/messages*' => Http::response(['recipient_id' => 'PSID5', 'message_id' => 'm_new']),
        ]);
    }

    public function test_connect_inbox_messages_and_send(): void
    {
        $i = MetaChatController::saveFromToken($this->user, 'SHORT');
        $this->assertSame(['LONG', ['P1' => 'PT1']], [$i->key('access_token'), $i->credentials['pages']]);

        $r = $this->getJson('/api/chat')->assertOk()->json();
        $this->assertSame([['instagram', '@yurizumi.id', 'dewi.beauty', 'IGU9', false], ['facebook', 'Toko Yurizumi', 'Andi', 'PSID5', true]],
            array_map(fn ($c) => [$c['channel'], $c['source_name'], $c['name'], $c['to_id'], $c['last_from_me']], $r['conversations']));
        $ch = collect($r['channels'])->keyBy('id');
        $this->assertTrue($ch['facebook']['available'] && $ch['instagram']['available']);
        $this->assertSame(['facebook'], array_unique(array_column($this->getJson('/api/chat?channel=facebook')->json('conversations'), 'channel')));

        $m = $this->getJson('/api/chat/facebook/P1/t_1')->assertOk()->json('messages');
        $this->assertSame([[false, 'Ready?'], [true, 'Siap kak']], array_map(fn ($x) => [$x['from_me'], $x['text']], $m));

        $this->postJson('/api/chat/facebook/P1', ['to_id' => 'PSID5', 'text' => 'Halo kak'])->assertOk()->assertJsonPath('message.id', 'm_new');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'P1/messages') && $req['recipient'] === ['id' => 'PSID5'] && $req['messaging_type'] === 'RESPONSE');

        $this->getJson('/api/chat/facebook/OTHER/t_1')->assertNotFound(); // a page that isn't connected
    }

    public function test_new_buyer_message_notifies_once(): void
    {
        $i = MetaChatController::saveFromToken($this->user, 'SHORT');
        $this->assertSame(0, \App\Support\Meta\MetaChatWatch::check($i)); // first run only remembers
        \Illuminate\Support\Facades\Cache::forget("meta:chat:seen:{$i->id}:IGC1");
        $this->assertSame(1, \App\Support\Meta\MetaChatWatch::check($i)); // IG message from buyer, unread
        $this->assertSame(0, \App\Support\Meta\MetaChatWatch::check($i));
        $n = \App\Models\AppNotification::sole();
        $this->assertSame(['Pesan Instagram baru', '/chat?channel=instagram&source=P1&c=IGC1'], [$n->title, $n->url]);
    }

    public function test_disconnect_revokes_on_facebook(): void
    {
        MetaChatController::saveFromToken($this->user, 'SHORT');
        Http::fake(['graph.facebook.com/*/me/permissions*' => Http::response(['success' => true])]);
        $this->deleteJson('/api/meta-chat')->assertNoContent();
        $this->assertSame(0, UserIntegration::count());
    }
}
