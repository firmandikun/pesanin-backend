<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogle(array $claims = []): void
    {
        config(['services.google.client_id' => 'cid.apps.googleusercontent.com']);
        Http::fake(['oauth2.googleapis.com/*' => Http::response($claims + [
            'aud' => 'cid.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com', 'sub' => '1234567890',
            'email' => 'Rina@Gmail.com', 'email_verified' => 'true', 'name' => 'Rina Kartika', 'given_name' => 'Rina',
        ])]);
    }

    public function test_first_google_sign_in_creates_a_store_on_trial(): void
    {
        $this->fakeGoogle();
        $res = $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertCreated();

        $this->assertSame('rina@gmail.com', $res->json('user.email'));
        $this->assertSame('Toko Rina', $res->json('user.store_name'));
        $this->assertSame('trial', $res->json('user.subscription.status'));
        $this->assertSame('1234567890', User::first()->google_id);
    }

    public function test_existing_email_account_is_linked_and_signed_in(): void
    {
        $this->fakeGoogle();
        $user = User::factory()->create(['email' => 'rina@gmail.com']);
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame(1, User::count());
    }

    public function test_token_for_another_app_is_rejected(): void
    {
        $this->fakeGoogle(['aud' => 'other-app.apps.googleusercontent.com']);
        $this->postJson('/api/auth/google', ['credential' => 'tok'])->assertStatus(422);
        $this->assertSame(0, User::count());
    }
}
