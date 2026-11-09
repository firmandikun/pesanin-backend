<?php

namespace Tests\Feature;

use App\Models\TeamMember;
use App\Models\User;
use App\Support\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Team members sign in with their own email/password and only reach what their role allows. */
class TeamLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@toko.test', 'password' => 'rahasia-owner', 'plan' => 'pro', 'plan_expires_at' => now()->addMonth()]);
    }

    private function member(array $attrs = []): array
    {
        Sanctum::actingAs($this->owner);
        $m = $this->postJson('/api/r/team', ['name' => 'Sari', 'email' => 'sari@toko.test', 'password' => 'sandi-sari-1', 'role' => 'cs', 'is_active' => true] + $attrs)->assertCreated()->json();
        $this->assertArrayNotHasKey('password', $m);
        $this->assertTrue($m['can_login']);
        $this->app['auth']->forgetGuards();

        return $m;
    }

    private function login(string $email, string $password)
    {
        return $this->postJson('/api/login', compact('email', 'password'));
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer $token");
    }

    public function test_member_logs_in_into_owner_store_and_is_limited_by_role(): void
    {
        $m = $this->member();
        $this->login('sari@toko.test', 'salah-sandi')->assertStatus(422);
        $r = $this->login('sari@toko.test', 'sandi-sari-1')->assertOk();
        $r->assertJsonPath('user.id', $this->owner->id)->assertJsonPath('user.member.id', $m['id'])->assertJsonPath('user.member.role', 'cs');
        $token = $r->json('token');

        $this->as($token)->getJson('/api/me')->assertJsonPath('member.name', 'Sari');
        $this->as($token)->getJson('/api/customers')->assertOk();
        $this->as($token)->getJson('/api/orders')->assertOk();
        $this->as($token)->getJson('/api/billing')->assertForbidden();
        $this->as($token)->getJson('/api/integrations')->assertForbidden();
        $this->as($token)->getJson('/api/r/team')->assertForbidden();
        $this->as($token)->putJson('/api/me/password', [])->assertForbidden();
        $this->as($token)->getJson('/api/reports/sales')->assertForbidden();

        // Notes are signed with the member's name.
        $this->as($token)->postJson('/api/chat/thread/notes', ['channel' => 'instagram', 'source' => '1', 'conversation' => 'c1', 'body' => 'cek stok'])
            ->assertJsonPath('note.author', 'Sari');

        // Owner still logs in as owner.
        $this->login('owner@toko.test', 'rahasia-owner')->assertJsonPath('user.member', null);
    }

    public function test_deactivating_or_changing_password_signs_member_out(): void
    {
        $m = $this->member();
        $token = $this->login('sari@toko.test', 'sandi-sari-1')->json('token');
        $this->as($token)->getJson('/api/me')->assertOk();

        Sanctum::actingAs($this->owner);
        $this->putJson("/api/r/team/{$m['id']}", ['name' => 'Sari', 'role' => 'cs', 'password' => ''])->assertOk(); // blank keeps password
        $this->assertTrue(TeamMember::find($m['id'])->can_login);
        $this->putJson("/api/r/team/{$m['id']}", ['is_active' => false])->assertOk();

        $this->as($token)->getJson('/api/me')->assertUnauthorized();
        $this->login('sari@toko.test', 'sandi-sari-1')->assertStatus(422);
    }

    public function test_member_email_must_be_unique_and_not_an_owner_email(): void
    {
        $this->member();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/r/team', ['name' => 'X', 'email' => 'sari@toko.test', 'password' => 'abcdefgh', 'role' => 'cs'])->assertStatus(422);
        $this->postJson('/api/r/team', ['name' => 'X', 'email' => 'owner@toko.test', 'password' => 'abcdefgh', 'role' => 'cs'])->assertStatus(422);
        $this->postJson('/api/r/team', ['name' => 'X', 'password' => 'abcdefgh', 'role' => 'cs'])->assertStatus(422); // password needs email
    }

    public function test_role_matrix(): void
    {
        $this->assertTrue(Member::allows('admin', 'GET', 'reports/sales'));
        $this->assertFalse(Member::allows('admin', 'GET', 'billing'));
        $this->assertFalse(Member::allows('admin', 'POST', 'meta-chat/connect'));
        $this->assertTrue(Member::allows('advertiser', 'GET', 'meta-ads/report'));
        $this->assertFalse(Member::allows('advertiser', 'POST', 'meta-ads/token'));
        $this->assertFalse(Member::allows('advertiser', 'GET', 'chat'));
        $this->assertTrue(Member::allows('gudang', 'POST', 'labels/shopee/ship'));
        $this->assertFalse(Member::allows('gudang', 'GET', 'customers'));
        $this->assertTrue(Member::allows('cs', 'PUT', 'chat/thread'));
        $this->assertFalse(Member::allows('cs', 'DELETE', 'products/1'));
    }
}
