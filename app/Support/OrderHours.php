<?php

namespace App\Support;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

/**
 * Peta Jam Ramai: when buyers order, per day of the week × hour (WIB), from the seller's own orders.
 * Shopee orders carry Shopee's create_time in created_at, so the hours match Seller Centre.
 * Used to time flash sales, ad budget boosts and broadcasts.
 */
class OrderHours
{
    public const TZ = 'Asia/Jakarta';

    /** Monday first, like the Indonesian calendar. */
    public const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];

    /**
     * @param  string  $channel  'shopee' (marketplace orders) or 'all' (web + marketplace)
     * @return array{grid: array<int, array<int, array{orders: int, revenue: int}>>, by_hour: array<int, int>, by_day: array<int, int>, summary: array, tips: array<int, string>}
     */
    public static function compute(User $user, int $days = 30, string $channel = 'shopee', ?int $shopId = null): array
    {
        $days = in_array($days, [7, 30, 60, 90], true) ? $days : 30;
        $since = now(self::TZ)->startOfDay()->subDays($days - 1)->utc();
        $q = Order::where('user_id', $user->id)->where('created_at', '>=', $since)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->where(fn ($w) => $w->whereNull('external_status')->orWhereNotIn('external_status', ['CANCELLED', 'IN_CANCEL']));
        if ($channel === 'shopee' || $shopId) {
            $shops = MarketplaceShop::where('user_id', $user->id)->where('platform', 'shopee')->when($shopId, fn ($s) => $s->whereKey($shopId))->pluck('id');
            $q->where('channel', 'shopee')->whereIn('marketplace_shop_id', $shops);
        }

        $grid = array_fill(0, 7, array_fill(0, 24, ['orders' => 0, 'revenue' => 0]));
        $total = 0;
        $revenue = 0;
        foreach ($q->toBase()->select(['created_at', 'total'])->cursor() as $o) {
            $t = Carbon::parse($o->created_at, 'UTC')->setTimezone(self::TZ);
            $d = $t->dayOfWeekIso - 1;
            $h = $t->hour;
            $grid[$d][$h]['orders']++;
            $grid[$d][$h]['revenue'] += (int) $o->total;
            $total++;
            $revenue += (int) $o->total;
        }

        $byHour = array_map(fn ($h) => array_sum(array_map(fn ($d) => $grid[$d][$h]['orders'], range(0, 6))), range(0, 23));
        $byDay = array_map(fn ($d) => array_sum(array_column($grid[$d], 'orders')), range(0, 6));

        return [
            'grid' => $grid, 'by_hour' => $byHour, 'by_day' => $byDay, 'days' => self::DAYS,
            'summary' => ['orders' => $total, 'revenue' => $revenue, 'days' => $days, 'max_cell' => max(array_map(fn ($r) => max(array_column($r, 'orders')), $grid))],
            'tips' => $total >= 20 ? self::tips($grid, $byHour, $byDay) : [],
        ];
    }

    /** Busiest 3-hour window of the day (wrapping past midnight). @return array{0: int, 1: int} [start hour, orders] */
    public static function peakWindow(array $byHour, int $width = 3): array
    {
        $best = [0, -1, -1];
        for ($h = 0; $h < 24; $h++) {
            $n = 0;
            for ($k = 0; $k < $width; $k++) {
                $n += $byHour[($h + $k) % 24];
            }
            // On a tie, prefer the window with the busy hour in the middle (19.00 alone → 18.00–21.00, not 17.00–20.00).
            $mid = $byHour[($h + intdiv($width, 2)) % 24];
            if ($n > $best[1] || ($n === $best[1] && $mid > $best[2])) {
                $best = [$h, $n, $mid];
            }
        }

        return [$best[0], $best[1]];
    }

    private static function tips(array $grid, array $byHour, array $byDay): array
    {
        $hh = fn (int $h) => str_pad((string) ($h % 24), 2, '0', STR_PAD_LEFT).'.00';
        $total = max(1, array_sum($byHour));
        [$start, $n] = self::peakWindow($byHour);
        $tips = [];
        $tips[] = "Jam paling ramai: {$hh($start)}–{$hh($start + 3)} WIB (".round($n / $total * 100).'% pesanan). Pilih slot Flash Sale Toko dan naikkan budget iklan di jam ini.';
        $tips[] = "Kirim broadcast/chat promo sekitar {$hh($start - 1)} WIB, satu jam sebelum jam ramai, supaya pembeli sudah siap checkout.";

        $bestDay = array_keys($byDay, max($byDay))[0];
        $worstDay = array_keys($byDay, min($byDay))[0];
        $tips[] = 'Hari paling ramai: '.self::DAYS[$bestDay].'. Hari paling sepi: '.self::DAYS[$worstDay].' — cocok untuk voucher khusus agar penjualan lebih rata.';

        // Night hours with almost no orders: the ads budget there is mostly wasted.
        $quiet = array_filter(range(0, 23), fn ($h) => $byHour[$h] / $total < 0.01);
        if (count($quiet) >= 3) {
            $tips[] = 'Pesanan hampir tidak ada jam '.self::ranges(array_values($quiet), $hh).' WIB. Kalau iklan tetap jalan di jam ini, pertimbangkan budget harian yang lebih kecil atau jadwal iklan.';
        }

        return $tips;
    }

    /** [0,1,2,5,6] → "00.00–03.00, 05.00–07.00" */
    private static function ranges(array $hours, callable $hh): string
    {
        $out = [];
        $from = $prev = array_shift($hours);
        foreach ([...$hours, null] as $h) {
            if ($h !== null && $h === $prev + 1) {
                $prev = $h;

                continue;
            }
            $out[] = $hh($from).'–'.$hh($prev + 1);
            [$from, $prev] = [$h, $h];
        }

        return implode(', ', $out);
    }
}
