<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\UserIntegration;
use App\Support\Biteship\Biteship;
use App\Support\Biteship\BiteshipException;
use App\Support\Midtrans\Midtrans;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Pengaturan → Integrasi (Biteship, Midtrans), their webhooks, and "Kirim via Biteship". */
class IntegrationController extends Controller
{
    private const PROVIDERS = ['rajaongkir', 'biteship', 'midtrans', 'shopee_affiliate', 'serpapi', 'whatsapp_cloud'];

    public function index(Request $request)
    {
        $rows = $request->user()->integrations()->get()->keyBy('provider');

        return collect(self::PROVIDERS)->mapWithKeys(fn ($p) => [$p => $this->present($rows[$p] ?? null, $p)]);
    }

    /** Save keys, verify them against the provider, and keep them only if they work. */
    public function save(Request $request, string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $user = $request->user();
        $existing = $user->integrations()->where('provider', $provider)->first();

        $data = $request->validate(in_array($provider, ['rajaongkir', 'serpapi'], true)
            ? ['api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:300']]
            : ($provider === 'whatsapp_cloud'
            ? ['phone_number_id' => [$existing ? 'nullable' : 'required', 'regex:/^\d{6,30}$/'], 'access_token' => [$existing ? 'nullable' : 'required', 'string', 'max:600'], 'app_secret' => ['nullable', 'string', 'max:100'], 'waba_id' => ['nullable', 'regex:/^\d{6,30}$/']]
            : ($provider === 'shopee_affiliate'
            ? ['app_id' => [$existing ? 'nullable' : 'required', 'string', 'max:100'], 'secret' => [$existing ? 'nullable' : 'required', 'string', 'max:200']]
            : ($provider === 'biteship'
            ? ['api_key' => [$existing ? 'nullable' : 'required', 'string', 'max:300'], 'cod_type' => 'nullable|in:7_days,5_days,3_days']
            : ['server_key' => [$existing ? 'nullable' : 'required', 'string', 'max:200'], 'client_key' => ['nullable', 'string', 'max:200'], 'signature_key' => ['nullable', 'string', 'max:200'], 'is_production' => 'required|boolean']))));

        $creds = array_merge($existing?->credentials ?? [], array_filter(collect($data)->only(['api_key', 'server_key', 'client_key', 'signature_key', 'app_id', 'secret', 'access_token', 'app_secret'])->all()));
        $integration = $existing ?? new UserIntegration(['user_id' => $user->id, 'provider' => $provider, 'webhook_token' => Str::random(40)]);
        $integration->fill([
            'credentials' => $creds,
            'is_production' => match ($provider) {
                'biteship' => ! str_starts_with((string) ($creds['api_key'] ?? ''), 'biteship_test'),
                'rajaongkir', 'shopee_affiliate', 'serpapi', 'whatsapp_cloud' => true,
                default => (bool) $data['is_production'],
            },
            'settings' => match ($provider) {
                'biteship' => ['cod_type' => $data['cod_type'] ?? ($existing?->settings['cod_type'] ?? '7_days')],
                'whatsapp_cloud' => array_merge($existing?->settings ?? [], array_filter(['phone_number_id' => $data['phone_number_id'] ?? null, 'waba_id' => $data['waba_id'] ?? null])),
                default => null,
            },
        ]);

        try {
            match ($provider) {
                'rajaongkir' => (new \App\Support\RajaOngkir\RajaOngkir($integration))->verify(),
                'shopee_affiliate' => (new \App\Support\Research\ShopeeAffiliate($integration))->verify(),
                'serpapi' => (new \App\Support\Research\GoogleTrends($integration))->account(),
                // Number details double as the connection test; kept to label the chat source.
                'whatsapp_cloud' => $integration->settings = array_merge($integration->settings ?? [], (fn ($n) => [
                    'display_phone' => $n['display_phone_number'] ?? null, 'verified_name' => $n['verified_name'] ?? null, 'quality' => $n['quality_rating'] ?? null,
                ])(\App\Support\WhatsApp\WhatsAppCloud::for($integration)->info())),
                'biteship' => (new Biteship($integration))->couriers(),
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

    /** Book Biteship shipments for web/manual orders: waybill issued, courier pickup requested. */
    public function ship(Request $request)
    {
        $data = $request->validate(['order_ids' => 'required|array|min:1|max:50', 'order_ids.*' => 'integer']);
        $user = $request->user();
        $client = Biteship::for($user->integration('biteship'));
        abort_unless($client, 422, 'Hubungkan Biteship dulu di Pengaturan → Integrasi.');

        $done = [];
        $failed = [];
        foreach ($user->orders()->whereIn('id', $data['order_ids'])->where('channel', '!=', 'shopee')->get() as $order) {
            if ($order->shipping_ref || $order->shipping_status !== 'not_shipped' || in_array($order->status, ['cancelled', 'refunded'], true)) {
                $failed[] = ['code' => $order->code, 'error' => 'sudah dikirim atau dibatalkan'];
                continue;
            }
            if ($order->payment_method !== 'cod' && $order->payment_status !== 'paid') {
                $failed[] = ['code' => $order->code, 'error' => 'belum dibayar'];
                continue;
            }
            try {
                Biteship::apply($order, $client->createOrder($order));
                $done[] = ['code' => $order->code, 'waybill' => $order->tracking_number];
            } catch (BiteshipException $e) {
                $failed[] = ['code' => $order->code, 'error' => $e->getMessage()];
            }
        }

        return ['shipped' => $done, 'failed' => $failed];
    }

    /**
     * Biteship webhook (URL shown in Pengaturan → Integrasi, one per seller). The payload is not
     * trusted as-is: the order is re-read from Biteship with the seller's key before applying.
     */
    public function biteshipWebhook(Request $request, string $token)
    {
        $integration = UserIntegration::where('provider', 'biteship')->where('webhook_token', $token)->first();
        $id = $request->input('order_id') ?? $request->input('id');
        if (! $integration || ! $id) {
            return response()->json(['ok' => true]); // Biteship's install check posts an empty body
        }
        $order = Order::where('user_id', $integration->user_id)->where('shipping_ref', $id)->first();
        if ($order) {
            try {
                Biteship::apply($order, (new Biteship($integration))->order($id));
            } catch (Throwable $e) {
                $integration->update(['last_error' => mb_substr('Webhook: '.$e->getMessage(), 0, 250)]);
            }
        }

        return response()->json(['ok' => true]);
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

        $order = Order::with('user.integrations')->where('code', $orderId)->first();
        $mt = $order ? Midtrans::forSeller($order->user?->integration('midtrans')) : null;
        abort_unless($order && $mt && $mt->validSignature($n), 403);

        Midtrans::applyToOrder($order, $n);

        return response()->json(['ok' => true]);
    }

    private function present(?UserIntegration $i, ?string $provider = null): array
    {
        $p = $provider ?? $i?->provider;
        $mask = fn (?string $k) => $k ? substr($k, 0, 12).'…'.substr($k, -4) : null;

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
                'biteship' => rtrim(config('app.url'), '/').'/api/biteship/webhook/'.$i->webhook_token,
                'whatsapp_cloud' => rtrim(config('services.whatsapp.webhook_base') ?: config('app.url'), '/').'/api/whatsapp/webhook/'.$i->webhook_token,
                default => null,
            },
            // WhatsApp asks for a verify token next to the callback URL; it is the same secret path token.
            'verify_token' => $i->provider === 'whatsapp_cloud' ? $i->webhook_token : null,
            'details' => $i->provider === 'whatsapp_cloud' ? array_intersect_key($i->settings ?? [], array_flip(['display_phone', 'verified_name', 'quality', 'connected_via', 'token_expires_at'])) : null,
        ];
    }
}
