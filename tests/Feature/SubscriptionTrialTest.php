<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTrialTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_store_starts_a_seven_day_trial(): void
    {
        $res = $this->postJson('/api/register', ['name' => 'Rina', 'store_name' => 'Toko Rina', 'email' => 'rina@x.test', 'password' => 'password123'])->assertCreated();

        $this->assertSame('trial', $res->json('user.subscription.status'));
        $this->assertSame('bisnis', $res->json('user.active_plan'));
        $this->assertSame(7, $res->json('user.subscription.days_left'));
    }

    public function test_expired_trial_locks_the_app_but_not_billing(): void
    {
        $user = User::factory()->create(['plan' => 'bisnis', 'plan_billing' => 'trial', 'plan_expires_at' => now()->subHour()]);
        $this->actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('subscription.status', 'expired')->assertJsonPath('subscription.reason', 'trial');
        $this->getJson('/api/orders')->assertStatus(402)->assertJsonPath('subscription_required', true);
        $this->getJson('/api/billing')->assertOk();

        $this->postJson('/api/billing/free')->assertOk()->assertJsonPath('subscription.status', 'free');
        $this->getJson('/api/orders')->assertOk();
    }

    public function test_paid_plan_keeps_working_during_grace_then_locks(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->subDays(3)]);
        $this->actingAs($user);
        $this->getJson('/api/me')->assertJsonPath('subscription.status', 'grace')->assertJsonPath('active_plan', 'pro');
        $this->getJson('/api/orders')->assertOk();

        $user->forceFill(['plan_expires_at' => now()->subDays(8)])->save();
        $this->getJson('/api/me')->assertJsonPath('subscription.status', 'expired')->assertJsonPath('subscription.reason', 'paid');
        $this->getJson('/api/orders')->assertStatus(402);
    }

    public function test_free_plan_cannot_be_picked_while_a_plan_is_running(): void
    {
        $this->actingAs(User::factory()->create(['plan' => 'starter', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->addDays(10)]));
        $this->postJson('/api/billing/free')->assertStatus(422);
    }

    public function test_unpaid_invoice_survives_a_midtrans_lookup_before_payment_page_is_opened(): void
    {
        config(['services.midtrans.server_key' => 'SB-Mid-server-test']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404)]);
        $user = User::factory()->create(['plan' => 'bisnis', 'plan_billing' => 'trial', 'plan_expires_at' => now()->subHour()]);
        $invoice = \App\Support\Subscriptions::createInvoice($user, 'bisnis', 'yearly', 'va_bca');

        $this->actingAs($user)->getJson('/api/billing')->assertOk();
        $this->assertSame('unpaid', $invoice->fresh()->status);
    }
}
