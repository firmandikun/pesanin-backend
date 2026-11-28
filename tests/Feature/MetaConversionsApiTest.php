<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\TrackingPixel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Meta Conversions API: Purchase from the server on checkout, hashed buyer data, same event_id as the browser pixel. */
class MetaConversionsApiTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['plan' => 'pro', 'plan_track' => 'social'])->save(); // tracking pixels are a Social & Web feature

        return $u;
    }

    public function test_token_is_write_only_and_blank_keeps_it(): void
    {
        $u = $this->seller();
        $id = $this->actingAs($u)->postJson('/api/r/pixels', ['platform' => 'facebook', 'pixel_id' => '123456789012345', 'server_side' => true, 'capi_token' => 'EAAsecret'])
            ->assertCreated()->assertJsonMissing(['capi_token' => 'EAAsecret'])->assertJsonPath('has_capi_token', true)->json('id');
        $this->actingAs($u)->putJson("/api/r/pixels/{$id}", ['capi_token' => '', 'capi_test_code' => 'TEST123'])->assertOk();
        $this->assertSame('EAAsecret', TrackingPixel::find($id)->capi_token);
        $this->assertStringNotContainsString('EAAsecret', $this->actingAs($u)->getJson('/api/r/pixels?platform=facebook')->getContent());
        $this->assertNotSame('EAAsecret', \DB::table('tracking_pixels')->where('id', $id)->value('capi_token')); // encrypted at rest
    }

    public function test_checkout_sends_purchase_to_meta_with_hashed_data(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $u = $this->seller();
        $mk = function (array $attrs) use ($u) {
            $p = new TrackingPixel($attrs + ['platform' => 'facebook']);
            $p->user_id = $u->id;
            $p->save();
        };
        $mk(['pixel_id' => '111111111111111', 'server_side' => true, 'capi_token' => 'tok-all', 'all_pages' => true]);
        $mk(['pixel_id' => '222222222222222', 'server_side' => true, 'capi_token' => 'tok-prod', 'all_pages' => false]); // used by the product below
        $mk(['pixel_id' => '333333333333333', 'server_side' => true, 'capi_token' => 'tok-other', 'all_pages' => false]); // not on this product
        $mk(['pixel_id' => '444444444444444', 'server_side' => false, 'capi_token' => 'tok-off', 'all_pages' => true]); // server side off

        $p = new Product(['name' => 'Ebook', 'slug' => 'ebook-capi', 'type' => 'digital', 'price' => 75000, 'status' => 'active', 'payment_methods' => ['bank_transfer'],
            'form_settings' => ['tracking' => ['pixels' => [['platform' => 'facebook', 'id' => '222222222222222']]]]]);
        $p->user_id = $u->id;
        $p->save();

        $code = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Test'])->postJson('/api/public/products/ebook-capi/checkout', [
            'customer_name' => 'Ani Lestari', 'phone' => '0812-3456-7890', 'email' => 'Ani@Mail.com', 'quantity' => 1, 'payment_method' => 'bank_transfer',
            'fbp' => 'fb.1.1700000000000.123456789', 'fbc' => 'fb.1.1700000000000.AbC_xyz', 'page_url' => 'https://toko.test/c/ebook-capi',
        ])->assertCreated()->json('order.code');

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertEqualsCanonicalizing(['111111111111111', '222222222222222'], $sent->map(fn (HttpRequest $r) => explode('/', parse_url($r->url(), PHP_URL_PATH))[2])->all());
        $r = $sent->first();
        $e = $r['data'][0];
        $this->assertSame(['Purchase', "purchase-{$code}", 'website', 'IDR', 75000.0], [$e['event_name'], $e['event_id'], $e['action_source'], $e['custom_data']['currency'], $e['custom_data']['value']]);
        $this->assertSame(hash('sha256', '6281234567890'), $e['user_data']['ph'][0]);
        $this->assertSame(hash('sha256', 'ani@mail.com'), $e['user_data']['em'][0]);
        $this->assertSame(hash('sha256', 'ani'), $e['user_data']['fn'][0]);
        $this->assertSame(hash('sha256', 'lestari'), $e['user_data']['ln'][0]);
        $this->assertSame(['fb.1.1700000000000.123456789', 'fb.1.1700000000000.AbC_xyz', 'Mozilla/5.0 Test'], [$e['user_data']['fbp'], $e['user_data']['fbc'], $e['user_data']['client_user_agent']]);
        $this->assertSame('https://toko.test/c/ebook-capi', $e['event_source_url']);
        $this->assertArrayNotHasKey('test_event_code', $r->data());
        $this->assertNotNull(TrackingPixel::where('pixel_id', '111111111111111')->first()->capi_last_at);
    }

    public function test_meta_error_is_recorded_and_connection_test_checks_token_only(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/555555555555555/events' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 400),
            'graph.facebook.com/v21.0/555555555555555*' => Http::response(['id' => '555555555555555', 'name' => 'Pixel']),
        ]);
        $u = $this->seller();
        $px = new TrackingPixel(['platform' => 'facebook', 'pixel_id' => '555555555555555', 'server_side' => true, 'capi_token' => 'bad']);
        $px->user_id = $u->id;
        $px->save();

        $this->actingAs($u)->postJson("/api/pixels/{$px->id}/capi-test")->assertOk()->assertJsonPath('mode', 'token');
        Http::assertNotSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events'));

        $this->actingAs($u)->postJson("/api/pixels/{$px->id}/capi-test", ['test_code' => 'TEST999'])->assertStatus(422)->assertJsonFragment(['message' => 'Meta menolak: Invalid OAuth access token.']);
        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/events') && $r['test_event_code'] === 'TEST999');
        $this->assertSame('Invalid OAuth access token.', $px->fresh()->capi_last_error);

        $this->actingAs(User::factory()->create())->postJson("/api/pixels/{$px->id}/capi-test")->assertNotFound();
    }
}
