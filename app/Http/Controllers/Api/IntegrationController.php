<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\UserIntegration;
use App\Support\Midtrans\Midtrans;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Pengaturan → Integrasi (KiriminAja, Midtrans, WhatsApp Cloud…) and their webhooks. */
class IntegrationController extends Controller
{
    private const PROVIDERS = ['kiriminaja', 'midtrans', 'shopee_affiliate', 'serpapi', 'whatsapp_cloud', 'gemini'];

    public function index(Request $request)
    {
        $rows = $request->user()->integrations()->get()->keyBy('provider');

        return collect(self::PROVIDERS)->mapWithKeys(fn ($p) => [$p => $this->present($rows[$p] ?? null, $p)]);
    }

    /** Save keys, verify them against the provider, and keep them only if they work. */
    /** Run only on Pesanin's own keys from .env: shown as status, not editable by sellers. */
    private const ENV_ONLY = ['kiriminaja', 'rajaongkir', 'midtrans'];

    public function save(Request $request, string $provider)
    {
        abort_if(in_array($provider, self::ENV_ONLY, true), 422, 'Integrasi ini dikelola Pesanin dan tidak perlu diisi.');
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $user = $request->user();
        $existing = $user->integrations()->where('provider', $provider)->first();

        $data = $request->validate($provider === 'rajaongkir'
            ? ['api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:300'], 'delivery_enabled' => 'sometimes|boolean', 'delivery_sandbox' => 'sometimes|boolean', 'delivery_vehicle' => 'sometimes|in:Motor,Mobil,Truk']
            : (in_array($provider, ['serpapi', 'gemini'], true)
            ? ['api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:300']]
            : ($provider === 'whatsapp_cloud'
            ? ['phone_number_id' => [$existing ? 'nullable' : 'required', 'regex:/^\d{6,30}$/'], 'access_token' => [$existing ? 'nullable' : 'required', 'string', 'max:600'], 'app_secret' => ['nullable', 'string', 'max:100'], 'waba_id' => ['nullable', 'regex:/^\d{6,30}$/']]
            : ($provider === 'shopee_affiliate'
            ? ['app_id' => [$existing ? 'nullable' : 'required', 'string', 'max:100'], 'secret' => [$existing ? 'nullable' : 'required', 'string', 'max:200']]
            : ['server_key' => [$existing ? 'nullable' : 'required', 'string', 'max:200'], 'client_key' => ['nullable', 'string', 'max:200'], 'signature_key' => ['nullable', 'string', 'max:200'], 'is_production' => 'required|boolean']))));

        $creds = array_merge($existing?->credentials ?? [], array_filter(collect($data)->only(['api_key', 'server_key', 'client_key', 'signature_key', 'app_id', 'secret', 'access_token', 'app_secret'])->all()));
        $integration = $existing ?? new UserIntegration(['user_id' => $user->id, 'provider' => $provider, 'webhook_token' => Str::random(40)]);
        $integration->fill([
            'credentials' => $creds,
            'is_production' => match ($provider) {
                'rajaongkir', 'shopee_affiliate', 'serpapi', 'whatsapp_cloud', 'gemini' => true,
                default => (bool) $data['is_production'],
            },
            'settings' => match ($provider) {
                // Komerce Delivery (pesan kurir + resi otomatis) uses the same Shipping API key.
                'rajaongkir' => array_merge($existing?->settings ?? [], ['delivery' => [
                    'enabled' => (bool) ($data['delivery_enabled'] ?? ($existing?->settings['delivery']['enabled'] ?? false)),
                    'sandbox' => (bool) ($data['delivery_sandbox'] ?? ($existing?->settings['delivery']['sandbox'] ?? false)),
                    'vehicle' => $data['delivery_vehicle'] ?? ($existing?->settings['delivery']['vehicle'] ?? 'Motor'),
                ]]),
                'whatsapp_cloud' => array_merge($existing?->settings ?? [], array_filter(['phone_number_id' => $data['phone_number_id'] ?? null, 'waba_id' => $data['waba_id'] ?? null])),
                default => null,
            },
        ]);

        try {
            match ($provider) {
                'rajaongkir' => [(new \App\Support\RajaOngkir\RajaOngkir($integration))->verify(),
                    ! empty($integration->settings['delivery']['enabled']) ? (new \App\Support\RajaOngkir\KomerceDelivery($integration))->verify() : null],
                'shopee_affiliate' => (new \App\Support\Research\ShopeeAffiliate($integration))->verify(),
                'serpapi' => (new \App\Support\Research\GoogleTrends($integration))->account(),
                'gemini' => \App\Support\AiBusinessDiagnosis::verifyKey((string) ($creds['api_key'] ?? '')),
                // Number details double as the connection test; kept to label the chat source.
                'whatsapp_cloud' => $integration->settings = array_merge($integration->settings ?? [], (fn ($n) => [
                    'display_phone' => $n['display_phone_number'] ?? null, 'verified_name' => $n['verified_name'] ?? null, 'quality' => $n['quality_rating'] ?? null,
                ])(\App\Support\WhatsApp\WhatsAppCloud::for($integration)->info())),
                default => (new Midtrans($creds['server_key'], (bool) $integration->is_production, $creds['signature_key'] ?? null))->verify(),
            };
        } catch (Throwable $e) {
            abort(422, 'Koneksi gagal: '.$e->getMessage());
        }
        if ($provider === 'whatsapp_cloud') {
            self::linkWaba($integration);
        }
        $integration->fill(['status' => 'active', 'last_error' => null, 'verified_at' => now()])->save();
        ActivityLog::record($user, "integration.{$provider}", ucfirst($provider).' terhubung');

        return $this->present($integration, $provider);
    }

    /**
     * Best effort: find the number's WhatsApp Business Account (needed for templates/broadcast)
     * and subscribe this app to it so incoming messages and delivery statuses reach the webhook.
     */
    public static function linkWaba(UserIntegration $i): void
    {
        try {
            $wa = \App\Support\WhatsApp\WhatsAppCloud::for($i);
            $waba = $wa->wabaId() ?? $wa->detectWaba();
            if ($waba) {
                $i->settings = array_merge($i->settings ?? [], ['waba_id' => $waba]);
                $wa->subscribeApp();
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('WhatsApp WABA link: '.$e->getMessage());
        }
    }

    public function destroy(Request $request, string $provider)
    {
        $request->user()->integrations()->where('provider', $provider)->delete();

        return response()->noContent();
    }

    /** Live KiriminAja courier options for one order so the seller can pick/change expedition before booking. */
    public function orderRates(Request $request, Order $order)
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $order->loadMissing('user', 'product');
        $pickup = $order->user->pickupAddress();
        if (! $pickup?->region) {
            return ['rates' => [], 'error' => 'Alamat pickup belum diisi (Pengaturan → Alamat).'];
        }
        $dest = $order->region_id ? \App\Models\Region::find($order->region_id) : null;
        if (! $dest) {
            return ['rates' => [], 'error' => 'Alamat pembeli belum lengkap (kecamatan tujuan kosong).'];
        }
        $weight = max(1, (int) ($order->weight ?: ($order->product?->weight ?? 1000) * max(1, (int) $order->quantity)));
        [$rates, $error] = \App\Support\CheckoutService::rates($request->user(), false, $pickup->region, $dest, $weight, (int) $order->subtotal);

        return ['rates' => $rates, 'error' => $error];
    }

    /** "Pesan kurir": book web/manual orders through KiriminAja (waybill + courier pickup). */
    public function book(Request $request)
    {
        $data = $request->validate([
            'order_ids' => 'required|array|min:1|max:50',
            'order_ids.*' => 'integer',
            'courier' => 'nullable|string|max:60',
            'courier_code' => 'nullable|string|max:80',
            'courier_service' => 'nullable|string|max:80',
            'shipping_cost' => 'nullable|integer|min:0',
            'pickup_method' => 'nullable|in:pickup,dropoff',
        ]);
        $user = $request->user();
        $platform = \App\Support\KiriminAja\KiriminAja::platform();
        $api = ! empty($platform?->settings['delivery']['enabled']) ? \App\Support\KiriminAja\KiriminAja::for($platform) : null;
        abort_unless($api, 422, 'Layanan pesan kurir Pesanin belum aktif. Sementara isi resi manual.');
        $done = [];
        $failed = [];
        $dropoff = ($data['pickup_method'] ?? 'pickup') === 'dropoff';
        $overrideCourier = count($data['order_ids']) === 1 && ! empty($data['courier_code']) && ! empty($data['courier_service']);
        foreach ($user->orders()->whereIn('id', $data['order_ids'])->get() as $order) {
            if ($overrideCourier) {
                $order->forceFill(array_filter([
                    'courier' => $data['courier'] ?? $order->courier,
                    'courier_code' => $data['courier_code'],
                    'courier_service' => $data['courier_service'],
                    'shipping_cost' => $data['shipping_cost'] ?? null,
                ], fn ($v) => $v !== null))->save();
            }
            if ($why = \App\Support\KiriminAja\CourierBooking::blocker($order, $user)) {
                $failed[] = ['code' => $order->code, 'error' => $why];

                continue;
            }
            $order->auto_ship_tries = 0;
            ($error = \App\Support\KiriminAja\CourierBooking::book($order, $api, $dropoff))
                ? $failed[] = ['code' => $order->code, 'error' => $error]
                : $done[] = ['code' => $order->code, 'waybill' => $order->tracking_number ?: $order->shipping_ref];
        }

        return ['shipped' => $done, 'failed' => $failed];
    }

    /** Midtrans payment notification for buyer orders (seller keys) and subscription invoices (platform keys). */
    public function midtransNotify(Request $request)
    {
        $n = $request->all();
        $orderId = (string) ($n['order_id'] ?? '');

        if (str_starts_with($orderId, 'INV-')) {
            $invoice = SubscriptionInvoice::where('number', str_replace('-', '/', $orderId))->first();
            $mt = Midtrans::platform();
            abort_unless($invoice && $mt && $mt->validSignature($n), 403);
            if (Midtrans::outcome($n) === 'paid' && $invoice->status === 'unpaid') {
                DB::transaction(function () use ($invoice) {
                    $invoice->update(['status' => 'paid', 'paid_at' => now()]);
                    $invoice->user->forceFill([
                        'plan' => $invoice->plan, 'plan_billing' => $invoice->billing,
                        'plan_expires_at' => Carbon::parse($invoice->period_end)->endOfDay(), 'plan_auto_renew' => true,
                    ])->save();
                });
                ActivityLog::record($invoice->user, 'billing.paid', "Pembayaran {$invoice->number} diterima (Midtrans)");
            } elseif (Midtrans::outcome($n) === 'failed' && $invoice->status === 'unpaid') {
                $invoice->update(['status' => 'cancelled']);
            }

            return response()->json(['ok' => true]);
        }

        // The payment page was made with the seller's Midtrans or, when they have none, Pesanin's platform account.
        // Accept either key, so a payment made on the platform still lands if the seller connects their own Midtrans later.
        $order = Order::with('user.integrations')->where('code', $orderId)->first();
        $valid = $order ? collect([Midtrans::platform(), Midtrans::legacySeller($order->user?->integration('midtrans'))])
            ->filter()->first(fn (Midtrans $mt) => $mt->validSignature($n)) : null;
        abort_unless($order && $valid, 403);

        Midtrans::applyToOrder($order, $n);

        return response()->json(['ok' => true]);
    }

    private function present(?UserIntegration $i, ?string $provider = null): array
    {
        $p = $provider ?? $i?->provider;
        // Enough to recognise which key is saved, never enough to reuse it.
        $mask = fn (?string $k) => $k ? substr($k, 0, 4).'…'.substr($k, -4) : null;

        if ($p === 'midtrans' && config('services.midtrans.server_key')) {
            return [
                'connected' => true,
                'is_production' => (bool) config('services.midtrans.production'),
                'verified_at' => now(),
                'last_error' => null,
                'settings' => null,
                'keys' => [
                    'server_key' => $mask(config('services.midtrans.server_key')),
                    'client_key' => $mask(config('services.midtrans.client_key')),
                    'signature_key' => $mask(config('services.midtrans.signature_key')),
                ],
                'webhook_url' => null,
                'verify_token' => null,
                'details' => null,
                'from_env' => true,
            ];
        }

        // KiriminAja runs on Pesanin's .env account only.
        if ($p === 'kiriminaja' || $p === 'rajaongkir') {
            $env = \App\Support\KiriminAja\KiriminAja::platform();
            if (! $env) {
                return ['connected' => false, 'from_env' => true];
            }
            return ['connected' => true, 'is_production' => ! config('services.kiriminaja.sandbox'), 'verified_at' => now(), 'last_error' => null,
                'settings' => $env->settings, 'keys' => ['api_key' => $mask($env->key('api_key'))], 'webhook_url' => null, 'verify_token' => null, 'details' => null, 'from_env' => true];
        }
        if ($p === 'midtrans' && ! config('services.midtrans.server_key')) {
            return ['connected' => false, 'from_env' => true];
        }

        if (! $i) {
            return ['connected' => false];
        }

        return [
            'connected' => $i->status === 'active',
            'is_production' => $i->is_production,
            'verified_at' => $i->verified_at,
            'last_error' => $i->last_error,
            'settings' => $i->settings,
            'keys' => match ($i->provider) {
                'midtrans' => ['server_key' => $mask($i->key('server_key')), 'client_key' => $mask($i->key('client_key')), 'signature_key' => $mask($i->key('signature_key'))],
                'shopee_affiliate' => ['app_id' => $mask($i->key('app_id')), 'secret' => $mask($i->key('secret'))],
                'whatsapp_cloud' => ['phone_number_id' => $i->settings['phone_number_id'] ?? null, 'waba_id' => $i->settings['waba_id'] ?? null, 'access_token' => $mask($i->key('access_token')), 'app_secret' => $mask($i->key('app_secret'))],
                default => ['api_key' => $mask($i->key('api_key'))],
            },
            'webhook_url' => match ($i->provider) {
                'whatsapp_cloud' => rtrim(config('services.whatsapp.webhook_base') ?: config('app.url'), '/').'/api/whatsapp/webhook/'.$i->webhook_token,
                default => null,
            },
            // WhatsApp asks for a verify token next to the callback URL; it is the same secret path token.
            'verify_token' => $i->provider === 'whatsapp_cloud' ? $i->webhook_token : null,
            'details' => $i->provider === 'whatsapp_cloud' ? array_intersect_key($i->settings ?? [], array_flip(['display_phone', 'verified_name', 'quality', 'connected_via', 'token_expires_at'])) : null,
        ];
    }
}
