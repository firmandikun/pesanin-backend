<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ScanOutParcel;
use App\Models\ScanOutSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Scan Out: record each parcel's resi once, match it to the seller's order (Shopee or web) to know the shop,
 * courier and buyer, and refuse a resi (or another barcode of the same order) that was already scanned.
 */
class ScanOut
{
    public const TTL_HOURS = 12;

    public static function normalize(string $code): string
    {
        return Str::upper(preg_replace('/\s+/', '', trim($code)));
    }

    public static function open(User $user, ?string $by = null): ScanOutSession
    {
        $s = new ScanOutSession([
            'token' => Str::random(48), 'status' => 'open', 'scanned_by' => $by ?: $user->name,
            'started_at' => now(), 'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
        $s->user_id = $user->id;
        $s->save();

        return $s;
    }

    /** The order a scanned code belongs to: resi first, then Shopee order number / Pesanin order code. */
    public static function findOrder(User $user, string $code): ?Order
    {
        return Order::where('user_id', $user->id)->where('is_abandoned', false)
            ->where(fn ($w) => $w->whereRaw('UPPER(tracking_number) = ?', [$code])->orWhere('external_order_sn', $code)->orWhere('code', $code))
            ->with('marketplaceShop:id,shop_name', 'product:id,name')
            ->orderByRaw('CASE WHEN UPPER(tracking_number) = ? THEN 0 ELSE 1 END', [$code])->latest('id')->first();
    }

    /**
     * Record one scan. @return array{status: 'ok'|'unknown'|'duplicate'|'not_resi'|'invalid', message: string, parcel: ?array}
     * "unknown" = not found in Pesanin's orders but still recorded (the parcel is physically going out).
     */
    public static function record(ScanOutSession $session, string $raw, ?string $by = null): array
    {
        $code = self::normalize($raw);
        if (mb_strlen($code) < 6 || mb_strlen($code) > 100) {
            return ['status' => 'invalid', 'message' => 'Kode terlalu pendek/panjang, scan ulang barcode resi.', 'parcel' => null];
        }
        $user = $session->user;
        $order = self::findOrder($user, $code);
        // The label also carries the order number's barcode: only the resi counts as a parcel.
        if (($order && Str::upper((string) $order->tracking_number) !== $code) || self::looksLikeOrderNumber($code)) {
            return ['status' => 'not_resi', 'parcel' => null,
                'message' => 'Ini nomor pesanan, bukan resi. Scan barcode resi di label (tidak dicatat).'];
        }

        $dupe = ScanOutParcel::where('user_id', $user->id)
            ->where(fn ($w) => $w->where('code', $code)->when($order, fn ($x) => $x->orWhere('order_id', $order->id)))
            ->with('session:id,started_at')->first();
        if ($dupe) {
            return ['status' => 'duplicate', 'parcel' => self::row($dupe),
                'message' => "Resi sudah di-scan {$dupe->scanned_at->timezone('Asia/Jakarta')->format('d M H:i')}".($dupe->scan_out_session_id === $session->id ? ' (di sesi ini)' : '').'. Jangan dikirim dobel.'];
        }

        $parcel = new ScanOutParcel([
            'scan_out_session_id' => $session->id, 'code' => $code, 'scanned_at' => now(), 'scanned_by' => $by ?: $session->scanned_by,
        ] + self::orderFields($user, $order));
        $parcel->user_id = $user->id;
        try {
            $parcel->save();
        } catch (QueryException) {
            // Same resi scanned twice at the same moment: the unique index keeps one.
            return ['status' => 'duplicate', 'message' => 'Resi sudah di-scan. Jangan dikirim dobel.', 'parcel' => null];
        }

        return $order
            ? ['status' => 'ok', 'message' => "{$parcel->shop_name} · {$parcel->courier}", 'parcel' => self::row($parcel)]
            : ['status' => 'unknown', 'message' => 'Resi tidak ditemukan di pesanan Pesanin, tetap dicatat. Hapus kalau salah scan.', 'parcel' => self::row($parcel)];
    }

    /** Shopee order SN: YYMMDD + 8 letters/digits (e.g. 261115CSXR0FDU); resi formats never look like this. */
    public static function looksLikeOrderNumber(string $code): bool
    {
        return (bool) preg_match('/^\d{6}(?=[A-Z0-9]*[A-Z])[A-Z0-9]{8}$/', $code);
    }

    private static function orderFields(User $user, ?Order $o): array
    {
        if (! $o) {
            return ['matched' => false, 'shop_name' => null, 'courier' => null, 'order_sn' => null, 'customer_name' => null, 'items_summary' => null];
        }
        $items = $o->channel === 'shopee' && $o->items
            ? collect($o->items)->map(fn ($i) => trim(($i['name'] ?? 'Produk').(! empty($i['variant']) ? " ({$i['variant']})" : '')).' x'.($i['qty'] ?? 1))->implode(', ')
            : ($o->product?->name ? "{$o->product->name}".($o->variant ? " ({$o->variant})" : '')." x{$o->quantity}" : null);

        return [
            'matched' => true, 'order_id' => $o->id, 'marketplace_shop_id' => $o->marketplace_shop_id,
            'shop_name' => $o->channel === 'shopee' ? ($o->marketplaceShop?->shop_name ?? 'Shopee') : (($user->store_name ?: 'Toko').' (Website)'),
            'courier' => trim(($o->courier ?? '').($o->courier_service && $o->courier_service !== $o->courier ? " {$o->courier_service}" : '')) ?: null,
            'order_sn' => $o->external_order_sn ?? $o->code,
            'customer_name' => OrderQuota::locked($o, $user) ? null : mb_substr((string) $o->customer_name, 0, 160),
            'items_summary' => $items ? mb_strimwidth($items, 0, 495, '…') : null,
        ];
    }

    public static function row(ScanOutParcel $p): array
    {
        return $p->only(['id', 'code', 'matched', 'shop_name', 'courier', 'order_sn', 'customer_name', 'items_summary', 'scanned_by'])
            + ['scanned_at' => $p->scanned_at?->toIso8601String()];
    }

    /** Count per shop / per courier and the total for one session. */
    public static function summary(ScanOutSession $s): array
    {
        $rows = $s->parcels()->get(['shop_name', 'courier', 'matched']);
        $group = fn (string $key, string $empty) => $rows->groupBy(fn ($r) => $r->{$key} ?: $empty)
            ->map(fn ($g, $k) => ['name' => $k, 'count' => $g->count()])->sortByDesc('count')->values();

        return [
            'total' => $rows->count(),
            'unknown' => $rows->where('matched', false)->count(),
            'by_shop' => $group('shop_name', 'Tidak dikenal'),
            'by_courier' => $group('courier', 'Tanpa kurir'),
        ];
    }

    /** Session + its parcels + summary, for the dashboard (with the QR token) and the PDF. */
    public static function payload(ScanOutSession $s, bool $withToken = false): array
    {
        $s->refresh();

        return [
            'session' => $s->only(['id', 'status', 'scanned_by']) + [
                'started_at' => $s->started_at?->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String(),
                'expires_at' => $s->expires_at?->toIso8601String(), 'open' => $s->isOpen(),
            ] + ($withToken ? ['token' => $s->token] : []),
            'store_name' => $s->user?->store_name,
            'parcels' => $s->parcels()->latest('scanned_at')->latest('id')->get()->map(fn ($p) => self::row($p))->values(),
            'summary' => self::summary($s),
        ];
    }
}
