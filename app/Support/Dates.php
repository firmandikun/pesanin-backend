<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Business day boundaries in WIB (Asia/Jakarta). Returned as UTC Carbons so they line up with timestamps the
 * database stores in UTC. The app timezone stays UTC (changing it would misread existing rows); only the
 * day boundaries are computed in WIB so "hari ini" and report ranges match Seller Centre.
 */
class Dates
{
    public const TZ = 'Asia/Jakarta';

    /** [start, end] of today in WIB, as UTC. */
    public static function today(): array
    {
        $start = Carbon::now(self::TZ)->startOfDay();

        return [$start->copy()->utc(), $start->copy()->endOfDay()->utc()];
    }

    /** [start, end] for from/to dates (Y-m-d) read as WIB days; defaults to the last 30 days. */
    public static function range(?string $from, ?string $to): array
    {
        $end = ($to ? Carbon::parse($to, self::TZ) : Carbon::now(self::TZ))->endOfDay();
        $start = ($from ? Carbon::parse($from, self::TZ) : $end->copy()->subDays(29))->startOfDay();

        return [$start->utc(), $end->utc()];
    }

    /** [start, end] covering the last $days WIB days (including today), as UTC. */
    public static function lastDays(int $days): array
    {
        $end = Carbon::now(self::TZ)->endOfDay();

        return [$end->copy()->subDays($days - 1)->startOfDay()->utc(), $end->copy()->utc()];
    }

    /** The WIB calendar date (Y-m-d) of a moment, e.g. a UTC created_at or a UTC range bound. */
    public static function day(\Carbon\CarbonInterface $at): string
    {
        return $at->copy()->setTimezone(self::TZ)->toDateString();
    }

    /** Every WIB date (Y-m-d) from $from to $to, both inclusive (bounds may be UTC moments). */
    public static function days(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
    {
        $out = [];
        for ($d = Carbon::parse(self::day($from)); $d->toDateString() <= self::day($to); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** SQL expression for the WIB date of a UTC timestamp column (WIB is a fixed UTC+7, no DST). */
    public static function sqlDay(string $column): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "DATE({$column}, '+7 hours')"
            : "DATE(DATE_ADD({$column}, INTERVAL 7 HOUR))";
    }
}
