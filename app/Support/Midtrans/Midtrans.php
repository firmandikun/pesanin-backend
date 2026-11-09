<?php

namespace App\Support\Midtrans;

use App\Models\Order;
use App\Models\SubscriptionInvoice;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Midtrans Snap. Buyer payments use the seller's own keys (money goes straight to the seller);
 * Pesanin subscription invoices use the platform keys from .env.
 * Notifications come back to /api/midtrans/notify (set per transaction via X-Override-Notification),
 * so sellers don't have to configure a notification URL in their Midtrans dashboard.
 */
class Midtrans
{
    /** Pesanin e-payment channel → Snap enabled_payments. */
    public const CHANNELS = [
        'qris' => ['other_qris', 'gopay', 'shopeepay'],
        'bca_va' => ['bca_va'], 'bni_va' => ['bni_va'], 'bri_va' => ['bri_va'], 'permata_va' => ['permata_va'],
        'mandiri_bill' => ['echannel'], 'gopay' => ['gopay'], 'shopeepay' => ['shopeepay'],
    ];

    public function __construct(private string $serverKey, private bool $production) {}

    public static function forSeller(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'midtrans' && $i->key('server_key') ? new self($i->key('server_key'), $i->is_production) : null;
    }

    public static function platform(): ?self
    {
        $key = config('services.midtrans.server_key');

        return $key ? new self($key, (bool) config('services.midtrans.production')) : null;
    }

    private function snapHost(): string
    {
        return $this->production ? 'https://app.midtrans.com' : 'https://app.sandbox.midtrans.com';
    }

    private function apiHost(): string
    {
        return $this->production ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com';
    }

    /** Snap transaction for a buyer order; returns ['token' => …, 'redirect_url' => …]. */
    public function forOrder(Order $order, ?array $channels, string $finishUrl): array
    {
        $enabled = collect($order->payment_channel ? [$order->payment_channel] : ($channels ?: []))
            ->flatMap(fn ($c) => self::CHANNELS[$c] ?? [])->unique()->values()->all();

        return $this->snap(array_filter([
            'transaction_details' => ['order_id' => $order->code, 'gross_amount' => (int) $order->total],
            'customer_details' => array_filter(['first_name' => $order->customer_name, 'phone' => $order->phone, 'email' => $order->email]),
            'item_details' => [['id' => (string) $order->product_id, 'price' => (int) $order->total, 'quantity' => 1, 'name' => mb_substr('Pesanan '.$order->code, 0, 50)]],
            'enabled_payments' => $enabled ?: null,
            'callbacks' => ['finish' => $finishUrl],
            'expiry' => ['unit' => 'hours', 'duration' => 24],
        ]));
    }

    public function forInvoice(SubscriptionInvoice $invoice, string $finishUrl): array
    {
        return $this->snap([
            'transaction_details' => ['order_id' => self::invoiceOrderId($invoice), 'gross_amount' => (int) $invoice->total],
            'customer_details' => array_filter(['first_name' => $invoice->user->name, 'email' => $invoice->user->email]),
            'callbacks' => ['finish' => $finishUrl],
            'expiry' => ['unit' => 'hours', 'duration' => 24],
        ]);
    }

    /** Midtrans order_id allows only letters, digits, - _ ~ . so "INV/PSN/…" becomes "INV-PSN-…". */
    public static function invoiceOrderId(SubscriptionInvoice $invoice): string
    {
        return str_replace('/', '-', $invoice->number);
    }

    /** Valid key → 404 "transaction doesn't exist"; wrong key → 401. Used by "Tes koneksi". */
    public function verify(): void
    {
        $res = Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(15)->get($this->apiHost().'/v2/pesanin-ping-'.time().'/status');
        $code = (int) ($res->json('status_code') ?? $res->status());
        if (in_array($code, [401, 403], true) || $res->status() === 401) {
            throw new RuntimeException('Server key ditolak Midtrans. Pastikan key dan mode (Sandbox/Production) sesuai.');
        }
    }

    /** Transaction status straight from Midtrans (same fields as a notification). */
    public function status(string $orderId): array
    {
        return Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(15)->get($this->apiHost()."/v2/{$orderId}/status")->json() ?? [];
    }

    /** Apply a verified notification/status to a buyer order. */
    public static function applyToOrder(\App\Models\Order $order, array $n): void
    {
        $outcome = self::outcome($n);
        $order->forceFill([
            'payment_gateway_status' => $n['transaction_status'] ?? $order->payment_gateway_status,
            'payment_ref' => $n['transaction_id'] ?? $order->payment_ref,
            'payment_channel' => $n['payment_type'] ?? $order->payment_channel,
        ]);
        if ($outcome === 'paid' && $order->payment_status !== 'paid') {
            $order->forceFill(['payment_status' => 'paid', 'paid_at' => now(), 'status' => $order->status === 'pending' ? 'processing' : $order->status]);
        } elseif ($outcome === 'failed' && $order->payment_status !== 'paid' && $order->status === 'pending') {
            $order->forceFill(['status' => 'cancelled']);
        }
        $order->save();
    }

    /** Midtrans signs notifications with sha512(order_id + status_code + gross_amount + server_key). */
    public function validSignature(array $n): bool
    {
        $expected = hash('sha512', ($n['order_id'] ?? '').($n['status_code'] ?? '').($n['gross_amount'] ?? '').$this->serverKey);

        return hash_equals($expected, (string) ($n['signature_key'] ?? ''));
    }

    /** paid | pending | failed */
    public static function outcome(array $n): string
    {
        return match ($n['transaction_status'] ?? '') {
            'settlement' => 'paid',
            'capture' => ($n['fraud_status'] ?? 'accept') === 'accept' ? 'paid' : 'pending',
            'pending', 'authorize' => 'pending',
            default => 'failed', // deny, cancel, expire, failure, refund
        };
    }

    private function snap(array $body): array
    {
        $res = Http::withBasicAuth($this->serverKey, '')->acceptJson()->timeout(20)
            ->withHeaders(['X-Override-Notification' => rtrim(config('app.url'), '/').'/api/midtrans/notify'])
            ->post($this->snapHost().'/snap/v1/transactions', $body);
        if ($res->failed() || ! $res->json('redirect_url')) {
            throw new RuntimeException('Midtrans: '.implode('; ', (array) ($res->json('error_messages') ?? ["HTTP {$res->status()}"])));
        }

        return ['token' => $res->json('token'), 'redirect_url' => $res->json('redirect_url')];
    }
}
