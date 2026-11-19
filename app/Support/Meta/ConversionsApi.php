<?php

namespace App\Support\Meta;

use App\Models\Order;
use App\Models\TrackingPixel;
use App\Support\Plans;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Meta Conversions API (server side). Sends Purchase from the server for every order placed on a checkout that runs
 * a saved Facebook pixel with "Server Side" on, so it is counted even with ad blockers or iOS limits. The browser
 * pixel sends the same event with the same event_id (purchase-{order code}), so Meta counts it once.
 * Buyer data is SHA-256 hashed as Meta requires; IP, user agent and the _fbp/_fbc cookies are sent as is.
 */
class ConversionsApi
{
    public const GRAPH = 'https://graph.facebook.com/v21.0';

    /** Saved Facebook pixels with CAPI configured that apply to this order's product. */
    public static function pixelsFor(Order $order): Collection
    {
        $order->loadMissing('user', 'product');
        if (! $order->user || ! Plans::hasFeature($order->user, 'pixels')) {
            return collect();
        }
        $product = $order->product;
        $productIds = collect($product?->form_settings['tracking']['pixels'] ?? [])
            ->merge($product?->success_settings['pixels'] ?? [])
            ->where('platform', 'facebook')->pluck('id')
            ->push($product?->pixels['facebook'] ?? null)->filter()->map(fn ($v) => trim((string) $v))->all();

        return TrackingPixel::where('user_id', $order->user_id)->where('platform', 'facebook')->where('server_side', true)->get()
            ->filter(fn (TrackingPixel $p) => $p->has_capi_token && ($p->all_pages || in_array($p->pixel_id, $productIds, true)))
            ->unique('pixel_id')->values();
    }

    /** @param array{ip?:?string,user_agent?:?string,fbp?:?string,fbc?:?string,url?:?string} $ctx */
    public static function purchase(Order $order, array $ctx = []): int
    {
        $pixels = self::pixelsFor($order);
        if ($pixels->isEmpty()) {
            return 0;
        }
        $event = [
            'event_name' => 'Purchase',
            'event_time' => ($order->created_at ?? now())->timestamp,
            'event_id' => 'purchase-'.$order->code,
            'action_source' => 'website',
            'event_source_url' => $ctx['url'] ?? null,
            'user_data' => self::userData($order, $ctx),
            'custom_data' => array_filter([
                'currency' => 'IDR',
                'value' => (float) $order->total,
                'order_id' => $order->code,
                'content_type' => 'product',
                'content_ids' => $order->product_id ? [(string) $order->product_id] : null,
                'contents' => $order->product_id ? [['id' => (string) $order->product_id, 'quantity' => (int) $order->quantity, 'item_price' => (float) $order->unit_price]] : null,
                'num_items' => (int) $order->quantity,
            ], fn ($v) => $v !== null),
        ];
        $event = array_filter($event, fn ($v) => $v !== null);

        return $pixels->filter(fn (TrackingPixel $p) => self::send($p, [$event]))->count();
    }

    /** Hashed buyer data (Meta "customer information parameters"). */
    public static function userData(Order $order, array $ctx = []): array
    {
        $h = fn (?string $v) => filled($v) ? hash('sha256', $v) : null;
        $norm = fn (?string $v) => $v === null ? null : preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($v)));
        $phone = preg_replace('/\D/', '', (string) $order->phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        } elseif (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }
        $parts = preg_split('/\s+/', trim((string) $order->customer_name)) ?: [];
        $first = $parts[0] ?? null;
        $last = count($parts) > 1 ? end($parts) : null;

        return array_filter([
            'em' => $order->email ? [$h(mb_strtolower(trim($order->email)))] : null,
            'ph' => $phone ? [$h($phone)] : null,
            'fn' => $first ? [$h(mb_strtolower($first))] : null,
            'ln' => $last ? [$h(mb_strtolower($last))] : null,
            'ct' => $order->city ? [$h($norm(preg_replace('/^(KOTA|KABUPATEN|KAB\.)\s+/i', '', $order->city)))] : null,
            'st' => $order->province ? [$h($norm($order->province))] : null,
            'zp' => $order->postal_code ? [$h(preg_replace('/\s/', '', $order->postal_code))] : null,
            'country' => [$h('id')],
            'external_id' => $phone ? [$h($phone)] : null,
            'client_ip_address' => $ctx['ip'] ?? $order->ip_address,
            'client_user_agent' => $ctx['user_agent'] ?? null,
            'fbp' => $ctx['fbp'] ?? null,
            'fbc' => $ctx['fbc'] ?? null,
        ], fn ($v) => filled($v));
    }

    /** Post events to one pixel; remembers the outcome on the pixel. */
    public static function send(TrackingPixel $pixel, array $events, ?string $testCode = null): bool
    {
        $body = ['data' => $events, 'access_token' => $pixel->capi_token];
        if ($code = $testCode ?? $pixel->capi_test_code) {
            $body['test_event_code'] = $code;
        }
        try {
            $res = Http::timeout(10)->asJson()->post(self::GRAPH."/{$pixel->pixel_id}/events", $body);
            $error = $res->successful() ? null : mb_substr((string) ($res->json('error.message') ?? "HTTP {$res->status()}"), 0, 300);
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $pixel->forceFill(['capi_last_at' => now(), 'capi_last_error' => $error])->saveQuietly();
        if ($error) {
            Log::warning("Meta CAPI pixel {$pixel->pixel_id}: {$error}");
        }

        return $error === null;
    }

    /**
     * "Tes koneksi". With a test code: a test Purchase that shows up only under Events Manager → Test Events.
     * Without one: just checks the token can read the pixel (no event, so ad stats stay clean). @return error or null
     */
    public static function test(TrackingPixel $pixel, ?string $testCode, ?string $ip, ?string $ua): ?string
    {
        if (! $testCode) {
            try {
                $res = Http::timeout(10)->get(self::GRAPH."/{$pixel->pixel_id}", ['fields' => 'id,name', 'access_token' => $pixel->capi_token]);
                $error = $res->successful() ? null : mb_substr((string) ($res->json('error.message') ?? "HTTP {$res->status()}"), 0, 300);
            } catch (Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 300);
            }
            $pixel->forceFill(['capi_last_at' => now(), 'capi_last_error' => $error])->saveQuietly();

            return $error;
        }
        $event = [
            'event_name' => 'Purchase', 'event_time' => now()->timestamp, 'event_id' => 'pesanin-test-'.now()->timestamp, 'action_source' => 'website',
            'user_data' => array_filter(['country' => [hash('sha256', 'id')], 'client_ip_address' => $ip, 'client_user_agent' => $ua]),
            'custom_data' => ['currency' => 'IDR', 'value' => 1000],
        ];

        return self::send($pixel, [$event], $testCode) ? null : $pixel->capi_last_error;
    }
}
