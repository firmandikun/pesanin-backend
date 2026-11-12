<?php

namespace Tests\Feature;

use App\Models\SubscriptionInvoice;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Platform admin: access control, overview, sellers, billing, content and the public content API. */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->seller = User::factory()->create(['store_name' => 'Toko Rina']);
        $this->seller->forceFill(['plan' => 'bisnis', 'plan_billing' => 'monthly', 'plan_expires_at' => now()->addDays(20)])->save();
    }

    public function test_only_platform_admins_can_open_admin_api(): void
    {
        Sanctum::actingAs($this->seller);
        $this->getJson('/api/admin/overview')->assertForbidden();
        $this->putJson("/api/admin/users/{$this->seller->id}", ['plan' => 'pro'])->assertForbidden();
        // is_admin can't be set through the profile form.
        $this->assertFalse(User::find($this->seller->id)->is_admin);

        Sanctum::actingAs($this->admin);
        $o = $this->getJson('/api/admin/overview')->assertOk()->json();
        $this->assertSame(1, $o['users']['total']);
        $this->assertSame(1, $o['users']['paying']);
        $this->assertSame(129000, $o['mrr']);
        $this->assertCount(30, $o['daily']);
    }

    public function test_admin_manages_sellers_and_suspension_locks_them_out(): void
    {
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/users?search=Rina')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.active_plan', 'bisnis');
        $this->putJson("/api/admin/users/{$this->seller->id}", ['plan' => 'pro', 'plan_expires_at' => now()->addMonth()->toDateString()])
            ->assertOk()->assertJsonPath('active_plan', 'pro');
        $this->putJson("/api/admin/users/{$this->admin->id}", ['suspended' => true])->assertStatus(422);

        $this->putJson("/api/admin/users/{$this->seller->id}", ['suspended' => true])->assertOk();
        $this->postJson('/api/login', ['email' => $this->seller->email, 'password' => 'password'])->assertUnprocessable();
        Sanctum::actingAs($this->seller->fresh());
        $this->getJson('/api/me')->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->putJson("/api/admin/users/{$this->seller->id}", ['suspended' => false])->assertOk()->assertJsonPath('suspended_at', null);
    }

    public function test_admin_confirms_invoice_payment(): void
    {
        $inv = $this->seller->subscriptionInvoices()->create(['number' => 'INV/1', 'plan' => 'pro', 'billing' => 'yearly', 'amount' => 2490000, 'tax' => 0, 'total' => 2490000,
            'method' => 'va_bca', 'status' => 'unpaid', 'period_start' => today(), 'period_end' => today()->addYear(), 'due_at' => now()->addDay()]);
        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/invoices?status=unpaid')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/admin/invoices/{$inv->id}/paid")->assertOk()->assertJsonPath('status', 'paid');
        $this->assertSame('pro', $this->seller->fresh()->plan);
        $this->postJson("/api/admin/invoices/{$inv->id}/paid")->assertStatus(422);
        $this->assertSame(1, SubscriptionInvoice::where('status', 'paid')->count());
    }

    public function test_content_crud_and_public_api(): void
    {
        $this->assertGreaterThan(5, Tutorial::count(), 'seeded by migration');
        Sanctum::actingAs($this->admin);
        $id = $this->postJson('/api/admin/content/tutorials', ['title' => 'Cara cek ROAS', 'category' => 'analitik', 'description' => 'x',
            'video_url' => 'https://www.youtube.com/watch?v=abc123', 'duration' => '4:25', 'steps' => ['Buka Iklan', ' ']])->assertCreated()->json('id');
        $this->postJson('/api/admin/content/tutorials', ['title' => 'x', 'category' => 'analitik', 'description' => 'x', 'video_url' => 'https://evil.example/x'])->assertUnprocessable();
        $this->postJson('/api/admin/content/blog-posts', ['title' => 'Draft', 'category' => 'Tips', 'excerpt' => 'e', 'body' => "Halo\n## Judul", 'is_published' => false])->assertCreated();
        $this->postJson('/api/admin/content/announcements', ['tag' => 'NEW', 'title' => 'Fitur admin', 'body' => 'Isi'])->assertCreated();
        $this->getJson('/api/admin/content/nope')->assertNotFound();

        $t = collect($this->getJson('/api/public/tutorials')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame(['Buka Iklan'], $t['steps']);
        $this->postJson("/api/public/tutorials/{$t['slug']}/view")->assertNoContent();
        $this->assertSame(1, Tutorial::find($id)->views);
        $this->assertNotContains('Draft', collect($this->getJson('/api/public/blog')->json('data'))->pluck('title'));
        $this->assertSame('Fitur admin', $this->getJson('/api/public/announcements')->json('data.0.title'));

        $this->deleteJson("/api/admin/content/tutorials/{$id}")->assertNoContent();
        $this->assertNull(Tutorial::find($id));
    }

    public function test_admin_changes_plans_features_and_site_settings(): void
    {
        Sanctum::actingAs($this->seller);
        $this->putJson('/api/admin/platform', ['site' => ['registration_open' => false]])->assertForbidden();

        Sanctum::actingAs($this->admin);
        $p = $this->getJson('/api/admin/platform')->assertOk()->json();
        $plans = collect($p['plans'])->keyBy('id')->map(fn ($x) => ['name' => $x['name'], 'monthly' => $x['monthly'], 'limits' => $x['limits']])->all();
        $plans['starter']['monthly'] = 59000;
        $plans['starter']['limits']['orders'] = 1500;
        $plans['pro']['limits']['team'] = null;
        $this->putJson('/api/admin/platform', ['plans' => $plans, 'features' => ['coupons' => 'starter'],
            'site' => ['registration_open' => false, 'notice' => 'Maintenance Sabtu 22.00', 'notice_tone' => 'warning', 'whatsapp' => '6281111111111']])->assertOk();

        $this->assertSame(59000, \App\Support\Plans::price('starter', 'monthly'));
        $this->assertSame(1500, \App\Support\Plans::all()['starter']['limits']['orders']);
        $this->assertNull(\App\Support\Plans::all()['pro']['limits']['team']);
        $this->assertSame('starter', \App\Support\Plans::features()['coupons']);

        $site = $this->getJson('/api/public/site')->assertOk()->json();
        $this->assertSame([false, 'Maintenance Sabtu 22.00', '6281111111111'], [$site['registration_open'], $site['notice'], $site['whatsapp']]);
        $this->assertSame(59000, collect($site['plans'])->firstWhere('id', 'starter')['monthly']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/register', ['name' => 'X', 'store_name' => 'X', 'email' => 'x@x.test', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertForbidden();
        $this->putJson('/api/admin/platform', ['site' => ['whatsapp' => '0812']])->assertUnauthorized();
    }

    public function test_blog_editor_endpoints(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        Sanctum::actingAs($this->admin);
        $url = $this->post('/api/admin/uploads', ['file' => \Illuminate\Http\UploadedFile::fake()->image('cover.jpg', 1200, 630)], ['Accept' => 'application/json'])->assertOk()->json('url');
        $this->assertStringContainsString('/storage/blog/', $url);
        $this->post('/api/admin/uploads', ['file' => \Illuminate\Http\UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

        $id = $this->postJson('/api/admin/content/blog-posts', ['title' => 'Tips HPP', 'category' => 'Keuangan', 'excerpt' => 'e', 'body' => "Halo\n![Grafik]($url)", 'cover_url' => $url])->assertCreated()->json('id');
        $this->getJson("/api/admin/content/blog-posts/{$id}")->assertOk()->assertJsonPath('cover_url', $url);
        $this->assertArrayHasKey('Keuangan', $this->getJson('/api/admin/blog-categories')->json());

        Sanctum::actingAs($this->seller);
        $this->post('/api/admin/uploads', ['file' => \Illuminate\Http\UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])->assertForbidden();
    }
}
