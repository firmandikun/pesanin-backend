<?php

namespace App\Support\KiriminAja;

use App\Models\Order;
use App\Models\User;
use App\Support\Notifier;
use App\Support\OrderAutomation;
use App\Support\OrderQuota;
use Throwable;

/**
 * Book web/manual orders through KiriminAja: creates the shipment + schedules pickup,
 * saving the order_id (`shipping_ref`) and waybill (`tracking_number`) on the order.
 */
class CourierBooking
{
    public const MAX_TRIES = 3;

    /** Why an order can't be booked right now, or null when it can. */
    public static function blocker(Order $order, User $user): ?string
    {
        return match (true) {
            $order->channel === 'shopee' => 'pesanan Shopee diatur dari menu Pesanan Shopee',
            (bool) $order->dropship_product_id => 'pesanan dropship dikirim oleh gudang Pesanin',
            (bool) $order->tracking_number => 'sudah punya resi',
            (bool) $order->shipping_ref => 'pickup sudah dijadwalkan',
            $order->shipping_status !== 'not_shipped' || in_array($order->status, ['cancelled', 'refunded'], true) => 'sudah dikirim atau dibatalkan',
            $order->payment_method !== 'cod' && $order->payment_status !== 'paid' => 'belum dibayar',
            ! $order->courier => 'kurir belum dipilih',
            OrderQuota::locked($order, $user) => 'data pembeli terkunci (kuota pesanan habis)',
            default => null,
        };
    }

    /** Book one order. @return string|null error message (null = waybill issued or pickup requested) */
    public static function book(Order $order, KiriminAja $api, bool $dropoff = false, array $opts = []): ?string
    {
        try {
            $res = $api->requestPickup($order, $dropoff, $opts);
            $order->forceFill([
                'shipping_ref' => $res['order_id'],
                'shipping_provider' => 'kiriminaja',
                'tracking_number' => $res['awb'] ?: $order->tracking_number,
                'auto_ship_error' => null,
                'status' => $order->status === 'pending' ? 'processing' : $order->status,
                // What KiriminAja booked: tariff, insurance, COD to collect, pickup time (shown on the order).
                'custom_fields' => array_merge((array) ($order->custom_fields ?? []), ['kiriminaja' => array_filter([
                    'pickup_number' => $res['pickup_number'], 'schedule' => $dropoff ? null : $res['schedule'], 'dropoff' => $dropoff,
                    'shipping_cost' => $res['shipping_cost'], 'insurance' => $res['insurance'], 'cod' => $res['cod'], 'cod_fee' => $res['cod_fee'],
                    'payment_status' => $res['payment_status'], 'qr_url' => $res['qr_url'], 'booked_at' => now()->toIso8601String(),
                ], fn ($v) => $v !== null && $v !== '')]),
            ])->save();

            return null;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // A wrong / missing KA Credit PIN: never retry automatically (KiriminAja locks the PIN after 3 wrong tries).
            $pinError = (bool) preg_match('/\bpin\b/i', $msg);
            if ($pinError) {
                $msg = 'PIN KA Credit ditolak KiriminAja. Isi KIRIMINAJA_PIN (6 digit) yang benar di .env server, lalu pesan kurir lagi. '
                    .'Pesan otomatis dihentikan untuk pesanan ini supaya PIN tidak terkunci (maks. 3x salah). ['.mb_substr($e->getMessage(), 0, 120).']';
            }
            $order->forceFill([
                'auto_ship_tries' => $pinError ? self::MAX_TRIES : $order->auto_ship_tries + 1,
                'auto_ship_error' => mb_substr($msg, 0, 300),
            ])->save();

            return $msg;
        }
    }

    /**
     * Scheduler (every 10 min): fallback for the webhook. Orders booked through KiriminAja in the last 14 days that
     * are not finished are checked with KiriminAja's tracking: the AWB once issued, and the delivery status.
     */
    public static function fetchAwbs(): int
    {
        $api = KiriminAja::for(KiriminAja::platform());
        if (! $api) {
            return 0;
        }
        $n = 0;
        $orders = Order::where('shipping_provider', 'kiriminaja')->whereNotNull('shipping_ref')
            ->whereIn('shipping_status', ['not_shipped', 'picked_up', 'in_transit'])->where('updated_at', '>=', now()->subDays(14))
            ->whereNotIn('status', ['cancelled', 'refunded'])
            // Orders without a resi first, then the ones checked longest ago.
            ->orderByRaw('tracking_number IS NOT NULL')->orderBy('updated_at')->limit(30)->get();
        foreach ($orders as $order) {
            try {
                $t = $api->track($order->shipping_ref);
                $d = $t['details'] ?? [];
                if (! $d) {
                    continue;
                }
                $awb = trim((string) ($d['awb'] ?? ''));
                $status = KiriminAja::mapShippingStatus(null, $d['status_code'] ?? null);
                $cf = (array) ($order->custom_fields ?? []);
                $cf['kiriminaja'] = array_merge((array) ($cf['kiriminaja'] ?? []), array_filter(['sorting_code' => $d['sorting_code'] ?? null, 'checked_at' => now()->toIso8601String()]));
                $newResi = $awb !== '' && ! $order->tracking_number;
                $order->forceFill(array_filter([
                    'tracking_number' => $newResi ? $awb : null,
                    'shipping_status' => $status && $status !== $order->shipping_status ? $status : null,
                    'status' => $status === 'delivered' ? 'completed' : null,
                    'completed_at' => $status === 'delivered' ? ($order->completed_at ?? now()) : null,
                    'custom_fields' => $cf,
                ], fn ($v) => $v !== null))->save();
                if ($newResi) {
                    Notifier::send($order->user_id, 'order', 'Resi terbit', "{$order->code}: {$awb}", '/orders?search='.$order->code, $order->id);
                    $n++;
                }
            } catch (Throwable) {
                // not issued yet / tracking unavailable: try again next round
            }
        }

        return $n;
    }

    /** Scheduler: paid (and, if allowed, COD) orders of sellers with "Resi otomatis" on. */
    public static function auto(): int
    {
        $done = 0;
        $api = KiriminAja::for(KiriminAja::platform());
        if (! $api || empty(KiriminAja::platform()?->settings['delivery']['enabled'])) {
            return 0;
        }
        foreach (User::where('settings->automation->auto_ship', true)->get()->filter(fn (User $u) => OrderAutomation::settings($u)['auto_ship']) as $user) {
            $cod = OrderAutomation::settings($user)['auto_ship_cod'];
            $orders = $user->orders()->where('is_abandoned', false)->where('channel', '!=', 'shopee')->whereNull('dropship_product_id')
                ->whereNull('tracking_number')->where('shipping_status', 'not_shipped')->whereNotIn('status', ['cancelled', 'refunded'])
                ->whereNotNull('courier')->where('auto_ship_tries', '<', self::MAX_TRIES)->where('created_at', '>=', now()->subDays(7))
                ->where(fn ($q) => $cod ? $q->where('payment_status', 'paid')->orWhere('payment_method', 'cod') : $q->where('payment_status', 'paid'))
                ->orderBy('id')->limit(20)->get();
            foreach ($orders as $order) {
                if (self::blocker($order, $user)) {
                    continue;
                }
                $error = self::book($order, $api);
                if (! $error) {
                    $done++;
                    Notifier::send($user->id, 'order', 'Resi otomatis terbit', "{$order->code}: {$order->tracking_number}", '/orders?search='.$order->code, $order->id);
                } elseif ($order->auto_ship_tries >= self::MAX_TRIES) {
                    Notifier::send($user->id, 'order', 'Resi otomatis gagal', "{$order->code}: {$error} Pesan kurir manual dari menu Pesanan.", '/orders?search='.$order->code, $order->id);
                }
            }
        }

        return $done;
    }
}
