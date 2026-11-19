<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\TrackingPixel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saved pixels (Pengaturan → Tracking) marked "Semua halaman" reach the public checkout and landing pages. */
class TrackingPixelTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_pixels_are_served_on_public_pages(): void
    {
        $seller = User::factory()->create();
        $seller->forceFill(['plan' => 'pro'])->save();
        $this->actingAs($seller)->postJson('/api/r/pixels', ['platform' => 'facebook', 'pixel_id' => '123456789012345', 'name' => 'Utama'])->assertCreated();
        $this->actingAs($seller)->postJson('/api/r/pixels', ['platform' => 'google_ads', 'pixel_id' => 'AW-1234567/AbC_d-1'])->assertCreated();
        $this->actingAs($seller)->postJson('/api/r/pixels', ['platform' => 'tiktok', 'pixel_id' => 'CABCDEF12345', 'all_pages' => false])->assertCreated();
        $this->actingAs($seller)->postJson('/api/r/pixels', ['platform' => 'snack', 'pixel_id' => 'x1'])->assertStatus(422);
        $this->actingAs($seller)->postJson('/api/r/pixels', ['platform' => 'facebook', 'pixel_id' => "1');alert(1);//"])->assertStatus(422);
        $other = new TrackingPixel(['platform' => 'facebook', 'pixel_id' => '999']); // another seller's pixel
        $other->user_id = User::factory()->create()->id;
        $other->save();

        $p = new Product(['name' => 'Ebook', 'slug' => 'ebook-px', 'type' => 'digital', 'price' => 50000, 'status' => 'active']);
        $p->user_id = $seller->id;
        $p->save();
        $global = $this->getJson('/api/public/products/ebook-px')->assertOk()->json('global_pixels');
        $this->assertSame(['123456789012345'], $global['facebook']);
        $this->assertSame([], $global['tiktok']); // not "Semua halaman"
        $this->assertSame(['AW-1234567/AbC_d-1'], $global['google_ads']);

        $lp = new LandingPage(['title' => 'LP', 'slug' => 'lp-px', 'status' => 'published', 'blocks' => []]);
        $lp->user_id = $seller->id;
        $lp->save();
        $this->assertSame(['123456789012345'], $this->getJson('/api/public/pages/lp-px')->assertOk()->json('global_pixels.facebook'));

        // Downgraded below the plan that includes Tracking pixel: saved pixels stop being installed.
        $seller->forceFill(['plan' => 'gratis'])->save();
        $this->assertSame([], $this->getJson('/api/public/products/ebook-px')->json('global_pixels.facebook'));
    }
}
