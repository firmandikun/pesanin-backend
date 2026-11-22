<?php

namespace App\Support;

use App\Models\BannedDevice;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Risiko COD: how likely a cash-on-delivery parcel comes back unpaid (RTS), from THIS store's own history with the
 * buyer's phone number (never other stores' data) and the order itself. 0–100; ≥60 high, ≥30 medium.
 * Shown on COD orders that are not shipped yet so CS can confirm by WhatsApp (or ask for a transfer) first.
 */
class CodRisk
{
    public const HIGH = 60;

    public const MEDIUM = 30;

    public static function phoneKey(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with($d, '62')) {
            $d = '0'.substr($d, 2);
        } elseif (str_starts_with($d, '8')) {
            $d = '0'.$d;
        }

        return strlen($d) >= 9 ? $d : null;
    }

    /** Orders that should get a score: COD, not cancelled, not yet with the courier. */
    public static function applies(Order $o): bool
    {
        return $o->payment_method === 'cod' && in_array($o->status, ['pending', 'processing'], true)
            && in_array($o->shipping_status, ['not_shipped', null], true) && ! $o->is_abandoned;
    }

    /** Attach `cod_risk` to the COD orders of a page (one query for all their phones). */
    public static function attach(Collection $orders, int $userId): void
    {
        $targets = $orders->filter(fn (Order $o) => self::applies($o) && self::phoneKey($o->phone));
        if ($targets->isEmpty()) {
            return;
        }
        $keys = $targets->map(fn ($o) => self::phoneKey($o->phone))->unique()->values();
        // Last 8 digits narrow the query; the exact normalized number is compared in PHP.
        $history = Order::where('user_id', $userId)->where('is_abandoned', false)->where('channel', '!=', 'shopee')
            // Phones are stored as typed ("0812-…", "+62 812 …"): compare digits only.
            ->where(function ($w) use ($keys) {
                foreach ($keys as $k) {
                    $w->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '+', ''), '.', '') LIKE ?", ['%'.substr($k, -8)]);
                }
            })
            ->get(['id', 'phone', 'status', 'shipping_status', 'payment_method', 'created_at'])
            ->groupBy(fn ($o) => self::phoneKey($o->phone));
        $banned = BannedDevice::where('user_id', $userId)->where('type', 'phone')->pluck('value')->map(fn ($v) => self::phoneKey($v))->filter()->flip();
        foreach ($targets as $o) {
            $k = self::phoneKey($o->phone);
            $o->setAttribute('cod_risk', self::score($o, collect($history[$k] ?? [])->where('id', '!=', $o->id), isset($banned[$k])));
        }
    }

    /** @return array{score: int, level: string, reasons: string[]} */
    public static function score(Order $o, Collection $past, bool $banned = false): array
    {
        $score = 0;
        $why = [];
        if ($banned) {
            return ['score' => 100, 'level' => 'high', 'reasons' => ['Nomor ini ada di daftar blokir toko']];
        }
        $rts = $past->where('shipping_status', 'returned')->count();
        $cancel = $past->where('status', 'cancelled')->where('payment_method', 'cod')->count();
        $ok = $past->filter(fn ($p) => $p->shipping_status === 'delivered' || $p->status === 'completed')->count();
        if ($rts) {
            $score += min(70, 45 * $rts);
            $why[] = "{$rts}× paket COD dikembalikan (RTS) sebelumnya";
        }
        if ($cancel) {
            $score += min(30, 12 * $cancel);
            $why[] = "{$cancel}× pesanan COD dibatalkan sebelumnya";
        }
        if ($ok) {
            $score -= min(40, 15 * $ok);
            $why[] = "{$ok}× pesanan diterima dengan baik";
        }
        if (! $past->count()) {
            $score += 10;
            $why[] = 'Pembeli baru';
            if ($o->total >= 500000) {
                $score += 10;
                $why[] = 'Nilai pesanan besar untuk pembeli baru';
            }
        }
        $sameDay = $past->filter(fn ($p) => $p->created_at && $o->created_at && abs($p->created_at->diffInHours($o->created_at)) <= 24 && $p->status !== 'cancelled')->count();
        if ($sameDay >= 2) {
            $score += 15;
            $why[] = "{$sameDay} pesanan lain dari nomor ini dalam 24 jam";
        }
        $addr = trim((string) $o->address);
        if (mb_strlen($addr) < 25 || ! preg_match('/\d|\b(rt|rw|no|blok|gg|gang|jl|jalan|dusun|desa|kp|kampung)\b/iu', $addr)) {
            $score += 15;
            $why[] = 'Alamat kurang lengkap';
        }
        $name = trim((string) $o->customer_name);
        if (mb_strlen($name) < 3 || preg_match('/\d{3,}|^(test|tes|coba|asd|qwe)/i', $name)) {
            $score += 10;
            $why[] = 'Nama pembeli mencurigakan';
        }
        $score = max(0, min(100, $score));

        return ['score' => $score, 'level' => $score >= self::HIGH ? 'high' : ($score >= self::MEDIUM ? 'medium' : 'low'), 'reasons' => $why];
    }
}
