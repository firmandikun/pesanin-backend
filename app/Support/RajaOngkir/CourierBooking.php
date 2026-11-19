<?php

namespace App\Support\RajaOngkir;

use App\Models\Order;
use App\Models\User;
use App\Support\Notifier;
use App\Support\OrderAutomation;
use App\Support\OrderQuota;
use Throwable;

/**
 * Book web/manual orders through Komerce Delivery: the order is created at Komerce (shipping_ref = KOM…), a courier
 * pickup is requested and the waybill lands on the order. Manual ("Pesan kurir") or automatic once paid
 * (Pengaturan → Otomatisasi Pesanan → Resi otomatis). Dropship orders are never booked by the seller.
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
            $order->shipping_status !== 'not_shipped' || in_array($order->status, ['cancelled', 'refunded'], true) => 'sudah dikirim atau dibatalkan',
            $order->payment_method !== 'cod' && $order->payment_status !== 'paid' => 'belum dibayar',
            ! $order->courier => 'kurir belum dipilih',
            OrderQuota::locked($order, $user) => 'data pembeli terkunci (kuota pesanan habis)',
            default => null,
        };
    }

    /** Book one order. @return string|null error message (null = waybill issued or pickup requested) */
    public static function book(Order $order, KomerceDelivery $api): ?string
    {
        try {
            if (! $order->shipping_ref) {
                $order->forceFill(['shipping_ref' => $api->store($order), 'shipping_provider' => 'komship'])->save();
            }
            $awb = $api->pickup([$order->shipping_ref])[$order->shipping_ref] ?? null;
            if (! $awb) {
                throw new \RuntimeException('Pickup belum diterima kurir, dicoba lagi otomatis.');
            }
            $order->forceFill(['tracking_number' => $awb, 'auto_ship_error' => null, 'status' => $order->status === 'pending' ? 'processing' : $order->status])->save();

            return null;
        } catch (Throwable $e) {
            $order->forceFill(['auto_ship_tries' => $order->auto_ship_tries + 1, 'auto_ship_error' => mb_substr($e->getMessage(), 0, 300)])->save();

            return $e->getMessage();
        }
    }

    /** Scheduler: paid (and, if allowed, COD) orders of sellers with "Resi otomatis" on. */
    public static function auto(): int
    {
        $done = 0;
        if (! KomerceDelivery::for(RajaOngkir::platform())) {
            return 0; // booking off in .env (KOMERCE_DELIVERY)
        }
        foreach (User::where('settings->automation->auto_ship', true)->get()->filter(fn (User $u) => OrderAutomation::settings($u)['auto_ship']) as $user) {
            $api = KomerceDelivery::for(RajaOngkir::platform());
            if (! $api) {
                continue;
            }
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
