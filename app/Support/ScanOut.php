<?php

namespace App\Support;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\ScanOutParcel;
use App\Models\ScanOutSession;
use App\Models\ShopeeBooking;
use App\Models\User;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Scan Out: record each parcel's resi once, match it to the seller's order (Shopee or web) to know the shop,
 * courier and buyer, and refuse a resi (or another barcode of the same order) that was already scanned.
 */
class ScanOut
{
    public const TTL_HOURS = 12;

    /** Riwayat Scan Out disimpan 10 hari, lalu dihapus (sesi + resinya). */
    public const KEEP_DAYS = 10;

    public static function prune(): int
    {
        return ScanOutSession::where('started_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

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
            ->where(fn ($w) => $w
                ->whereRaw("REPLACE(UPPER(TRIM(tracking_number)), ' ', '') = ?", [$code])
                ->orWhereRaw('UPPER(TRIM(external_order_sn)) = ?', [$code])
                ->orWhereRaw('UPPER(TRIM(code)) = ?', [$code]))
            ->with('marketplaceShop:id,shop_name', 'product:id,name')
            ->orderByRaw("CASE WHEN REPLACE(UPPER(TRIM(tracking_number)), ' ', '') = ? THEN 0 ELSE 1 END", [$code])
            ->latest('id')->first();
    }

    /**
     * Record one scan. @return array{status: 'ok'|'unknown'|'duplicate'|'not_resi'|'invalid', message: string, parcel: ?array}
     * "unknown" = not found in Pesanin's orders but still recorded (the parcel is physically going out).
     */
    public static function record(ScanOutSession $session, string $raw, ?string $by = null): array
    {
        $code = self::normalize($raw);
        if (preg_match('#^(HTTPS?://|WWW\.)#i', $code) || str_contains($code, '://') || str_contains($code, '/SCAN/')) {
            return ['status' => 'invalid', 'message' => 'Ini QR link / URL, bukan barcode resi paket. Arahkan kamera ke barcode resi di label pengiriman.', 'parcel' => null];
        }
        $user = $session->user;
        // Some courier QR codes embed delimiters (e.g. "SPXID123|..."); extract a matching resi token if present.
        if (! preg_match('/^[A-Z0-9\-_]+$/', $code)) {
            $matchedToken = collect(preg_split('/[\s|,;]+/', Str::upper(trim($raw))) ?: [])
                ->map(fn ($t) => preg_replace('/[^A-Z0-9\-_]/', '', (string) $t))
                ->first(fn ($t) => strlen($t) >= 6 && strlen($t) <= 100 && (self::findOrder($user, $t) || self::findBooking($user, $t)));
            if ($matchedToken) {
                $code = $matchedToken;
            } else {
                return ['status' => 'invalid', 'message' => 'Format bukan nomor resi yang valid. Scan ulang barcode resi di label pengiriman.', 'parcel' => null];
            }
        }
        if (mb_strlen($code) < 6 || mb_strlen($code) > 100) {
            return ['status' => 'invalid', 'message' => 'Kode terlalu pendek/panjang, scan ulang barcode resi.', 'parcel' => null];
        }
        $order = self::findOrder($user, $code);
        // The label also carries the order number's barcode: only the resi counts as a parcel
        // (or Pesanin's own order code on non-Shopee labels that have no courier tracking_number yet).
        $normalizedOrderResi = $order ? self::normalize((string) $order->tracking_number) : '';
        $isWebOrderLabelCode = $order && $order->channel !== 'shopee' && $normalizedOrderResi === '' && self::normalize((string) $order->code) === $code;
        $isResi = ($order && $normalizedOrderResi === $code) || $isWebOrderLabelCode;
        if (! $isResi && ($order || (self::looksLikeOrderNumber($code) && ! self::findBooking($user, $code)))) {
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
        ] + ($order ? self::orderFields($user, $order) : self::bookingFields(self::findBooking($user, $code))));
        $parcel->user_id = $user->id;
        try {
            $parcel->save();
        } catch (QueryException) {
            // Same resi scanned twice at the same moment: the unique index keeps one.
            return ['status' => 'duplicate', 'message' => 'Resi sudah di-scan. Jangan dikirim dobel.', 'parcel' => null];
        }

        if (! $parcel->matched) {
            // Label arranged in Seller Centre but not synced yet: pull fresh resi from Shopee after replying, the
            // parcel is re-matched to its shop on the next poll.
            $uid = $user->id;
            dispatch(fn () => self::refreshTracking(User::find($uid)))->afterResponse();
        }

        return $parcel->matched
            // The order's products, for "Cek isi paket" (scan each product + photo before the parcel is closed).
            ? ['status' => 'ok', 'message' => "{$parcel->shop_name} · {$parcel->courier}", 'parcel' => self::row($parcel), 'order' => $order ? Packing::row($order) : null]
            : ['status' => 'unknown', 'message' => 'Resi tidak ditemukan di pesanan Pesanin, tetap dicatat. Hapus kalau salah scan.', 'parcel' => self::row($parcel)];
    }

    /**
     * Order-number barcodes printed on labels next to the resi: Shopee order SN (YYMMDD + 8 letters/digits, e.g.
     * 261115CSXR0FDU) and the 13-digit order number (e.g. 5846207378888). A code that is a known resi always wins.
     */
    public static function looksLikeOrderNumber(string $code): bool
    {
        return (bool) preg_match('/^(\d{6}(?=[A-Z0-9]*[A-Z])[A-Z0-9]{8}|\d{13})$/', $code);
    }

    /** Shopee "Pengiriman Kilat" bookings have their own resi before they are matched to a buyer order. */
    public static function findBooking(User $user, string $code): ?ShopeeBooking
    {
        return ShopeeBooking::where('user_id', $user->id)->whereRaw("REPLACE(UPPER(TRIM(tracking_number)), ' ', '') = ?", [$code])
            ->with('marketplaceShop:id,shop_name')->latest('id')->first();
    }

    private static function bookingFields(?ShopeeBooking $b): array
    {
        return $b
            ? ['matched' => true, 'marketplace_shop_id' => $b->marketplace_shop_id, 'shop_name' => $b->marketplaceShop?->shop_name ?? 'Shopee',
                'courier' => $b->shipping_carrier, 'order_sn' => $b->order_sn ?? $b->booking_sn, 'customer_name' => null, 'items_summary' => null]
            : ['matched' => false, 'shop_name' => null, 'courier' => null, 'order_sn' => null, 'customer_name' => null, 'items_summary' => null];
    }

    /**
     * Fetch the resi Shopee issued for orders Pesanin hasn't stored yet (arranged in Seller Centre / another tool, or
     * the resi came after the last sync). Automatic runs: at most once a minute, after the response. $force (the
     * "Cocokkan ulang" button): re-reads every open Shopee order and reports what it did.
     * @return array{shops: int, orders: int, resi: int, errors: string[]}
     */
    public static function refreshTracking(?User $user, bool $force = false): array
    {
        $stats = ['shops' => 0, 'orders' => 0, 'resi' => 0, 'errors' => []];
        if (! $user || (! $force && ! Cache::add("scanout-trk:{$user->id}", 1, 60))) {
            return $stats;
        }
        @set_time_limit(180);
        $api = new ShopeeClient;
        foreach (MarketplaceShop::where('user_id', $user->id)->where('platform', 'shopee')->where('is_demo', false)->get() as $shop) {
            $stats['shops']++;
            try {
                $sync = new ShopeeSync($api);
                // Open orders (perlu dikirim / diproses / dikirim) straight from Shopee; newly arranged ones get their resi.
                $stats['orders'] += $force ? $sync->reconcileOpen($shop) : $sync->recent($shop, 180);
                $missing = Order::where('marketplace_shop_id', $shop->id)->where(fn ($w) => $w->whereNull('tracking_number')->orWhere('tracking_number', ''))
                    ->whereNotIn('external_status', ['UNPAID', 'CANCELLED', 'IN_CANCEL', 'COMPLETED', 'TO_RETURN'])
                    ->where('created_at', '>=', now()->subDays(30))->latest('id')->limit($force ? 150 : 40)->get(['id', 'external_order_sn']);
                foreach ($missing as $o) {
                    try {
                        if ($tn = trim((string) $api->trackingNumber($shop, $o->external_order_sn))) {
                            $o->update(['tracking_number' => $tn]);
                            $stats['resi']++;
                        }
                    } catch (\Throwable) {
                        // not arranged yet
                    }
                }
            } catch (\Throwable $e) {
                $stats['errors'][] = ($shop->shop_name ?: "Toko {$shop->id}").': '.$e->getMessage();
                Log::warning("ScanOut refresh resi shop {$shop->id}: {$e->getMessage()}");
            }
        }

        return $stats;
    }

    /** Give unknown parcels of a session their shop once the order / resi shows up in Pesanin. */
    public static function rematch(ScanOutSession $s): void
    {
        $user = $s->user;
        foreach ($s->parcels()->where('matched', false)->get() as $p) {
            $order = self::findOrder($user, $p->code);
            if ($order && self::normalize((string) $order->tracking_number) === $p->code
                && ! ScanOutParcel::where('user_id', $user->id)->where('order_id', $order->id)->exists()) {
                $p->update(self::orderFields($user, $order));
            } elseif (! $order && ($b = self::findBooking($user, $p->code))) {
                $p->update(self::bookingFields($b));
            }
        }
    }

    private static function orderFields(User $user, Order $o): array
    {
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

    /**
     * The expedition a parcel goes with (the courier who picks it up), without the service level:
     * from the order's courier when known ("SPX Standard" → SPX Express), otherwise from the resi format.
     */
    public static function expedition(?string $courier, string $code): string
    {
        $c = Str::lower((string) $courier);
        $byName = [
            'SPX Express' => ['spx', 'shopee express', 'shopee xpress'], 'J&T Express' => ['j&t', 'jnt', 'j & t'], 'J&T Cargo' => ['j&t cargo', 'jt cargo'],
            'JNE' => ['jne'], 'SiCepat' => ['sicepat'], 'Anteraja' => ['anteraja'], 'Ninja Xpress' => ['ninja'], 'ID Express' => ['id express', 'idexpress'],
            'POS Indonesia' => ['pos '], 'Lion Parcel' => ['lion'], 'GoSend' => ['gosend', 'gojek'], 'GrabExpress' => ['grab'], 'Paxel' => ['paxel'],
            'Wahana' => ['wahana'], 'TIKI' => ['tiki'], 'SAP Express' => ['sap'], 'Lex' => ['lex', 'lazada'],
        ];
        if ($c !== '') {
            foreach (['J&T Cargo', ...array_keys($byName)] as $name) {
                foreach ($byName[$name] as $needle) {
                    if (str_contains($c.' ', $needle)) {
                        return $name;
                    }
                }
            }

            return Str::title(trim(preg_replace('/\b(reguler|regular|standard|standar|ekonomi|economy|eco|hemat|kargo|cargo|instant|sameday|next ?day|yes|oke|reg)\b/i', '', (string) $courier))) ?: (string) $courier;
        }

        return match (true) {
            str_starts_with($code, 'SPX') => 'SPX Express',
            (bool) preg_match('/^(JX|JP|JD|JO|JZ|JT)\d{8,}$/', $code) => 'J&T Express',
            (bool) preg_match('/^(CGK|BDO|SUB|MES|JOG|SRG|DPS|UPG|PLM|BPN|PKU|JNE)/', $code) => 'JNE',
            str_starts_with($code, 'NLID') || str_starts_with($code, 'NV') => 'Ninja Xpress',
            (bool) preg_match('/^ID[ESX]/', $code) => 'ID Express',
            str_starts_with($code, 'LP') => 'Lion Parcel',
            str_starts_with($code, 'GK-') || str_starts_with($code, 'GK') && strlen($code) > 15 => 'GoSend',
            (bool) preg_match('/^1[01]\d{12}$/', $code) => 'Anteraja',
            (bool) preg_match('/^00\d{10}$/', $code) => 'SiCepat',
            default => 'Ekspedisi lain',
        };
    }

    public static function row(ScanOutParcel $p): array
    {
        return $p->only(['id', 'code', 'matched', 'shop_name', 'courier', 'order_sn', 'customer_name', 'items_summary', 'scanned_by'])
            + ['scanned_at' => $p->scanned_at?->toIso8601String(), 'expedition' => self::expedition($p->courier, $p->code)];
    }

    /** Count per shop / per expedition and the total for one session. */
    public static function summary(ScanOutSession $s): array
    {
        self::rematch($s);
        $rows = $s->parcels()->get(['code', 'shop_name', 'courier', 'matched', 'scanned_by'])->each(fn ($r) => $r->expedition = self::expedition($r->courier, $r->code));
        $group = fn (string $key, string $empty) => $rows->groupBy(fn ($r) => $r->{$key} ?: $empty)
            ->map(fn ($g, $k) => ['name' => $k, 'count' => $g->count()])->sortByDesc('count')->values();

        return [
            'total' => $rows->count(),
            'unknown' => $rows->where('matched', false)->count(),
            'by_shop' => $group('shop_name', 'Tidak dikenal'),
            'by_expedition' => $group('expedition', 'Ekspedisi lain'),
            // Several staff scanning with the same QR: parcels per person.
            'by_staff' => $group('scanned_by', 'Tanpa nama'),
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
