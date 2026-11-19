<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DropshipVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_uploads_video_and_others_cannot(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $r = $this->actingAs($admin)->post('/api/admin/dropship/videos', ['file' => UploadedFile::fake()->create('promo.mp4', 2048, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('/storage/dropship/videos/', $r->json('url'));
        Storage::disk('public')->assertExists('dropship/videos/'.basename($r->json('url')));

        $this->actingAs($admin)->post('/api/admin/dropship/videos', ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->actingAs($admin)->post('/api/admin/dropship/videos', ['file' => UploadedFile::fake()->create('besar.mp4', 204801, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonFragment(['Ukuran video maksimal 200 MB.']);

        $seller = User::factory()->create();
        $this->actingAs($seller)->post('/api/admin/dropship/videos', ['file' => UploadedFile::fake()->create('promo.mp4', 100, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_slide_pack_downloads_as_zip_with_only_own_images(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('blog/s1.png', 'PNGDATA');
        \Illuminate\Support\Facades\Http::fake(['localhost:3000/images/dropship/a.jpg' => \Illuminate\Support\Facades\Http::response('JPGDATA', 200, ['Content-Type' => 'image/jpeg']), '*' => \Illuminate\Support\Facades\Http::response('nope', 500)]);
        config(['app.frontend_url' => 'http://localhost:3000']);
        $dp = \App\Models\DropshipProduct::create(['sku' => 'DS-Z', 'product_type' => 'digital', 'name' => 'Bundle', 'category' => 'Template', 'status' => 'active',
            'slides' => [['id' => 'p1', 'title' => 'Carousel', 'copywriting' => 'Caption', 'hashtags' => '#promo', 'slides' => [
                ['id' => 'a', 'image' => asset('storage/blog/s1.png'), 'label' => 'Hook'],
                ['id' => 'b', 'image' => '/images/dropship/a.jpg', 'label' => 'Demo'],
                ['id' => 'c', 'image' => 'http://169.254.169.254/latest/meta-data', 'label' => 'Luar'],
            ]]]]);
        $seller = User::factory()->create();

        $res = $this->actingAs($seller)->get("/api/dropship/products/{$dp->id}/slides/p1/zip")->assertOk();
        $zip = new \ZipArchive;
        $zip->open($res->baseResponse->getFile()->getPathname());
        $this->assertSame('PNGDATA', $zip->getFromName('01-hook.png'));
        $this->assertSame('JPGDATA', $zip->getFromName('02-demo.jpg'));
        $this->assertStringContainsString('#promo', $zip->getFromName('caption.txt'));
        $this->assertStringContainsString('169.254.169.254', $zip->getFromName('gambar-tidak-terunduh.txt'));
        $this->assertSame(4, $zip->numFiles);
        \Illuminate\Support\Facades\Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));

        $this->actingAs($seller)->get("/api/dropship/products/{$dp->id}/slides/nope/zip")->assertNotFound();
    }
}
