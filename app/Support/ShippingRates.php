<?php

namespace App\Support;

use App\Models\Region;

/**
 * Local shipping-rate engine. Rates depend on the zone between origin and
 * destination and the weight (rounded up to whole kg, min 1 kg).
 * Swap rates() for a courier aggregator API in production.
 */
class ShippingRates
{
    /** code => [name, service, base rate per kg (same city), etd by zone] */
    public const COURIERS = [
        'jne' => ['JNE', 'REG', 10000, ['1 - 2 hari', '1 - 2 hari', '2 - 3 hari', '3 - 5 hari', '4 - 7 hari']],
        'sicepat' => ['SiCepat', 'REG', 7000, ['1 - 2 hari', '2 - 3 hari', '2 - 3 hari', '3 - 5 hari', '4 - 7 hari']],
        'sap' => ['SAP', 'REG', 7000, ['1 - 2 hari', '1 - 3 hari', '2 - 3 hari', '3 - 5 hari', '5 - 8 hari']],
        'idexpress' => ['iDexpress', 'REG', 9000, ['1 - 2 hari', '2 - 3 hari', '2 - 4 hari', '3 - 6 hari', '5 - 8 hari']],
        'jnt' => ['J&T', 'REG', 9000, ['1 - 2 hari', '2 - 3 hari', '2 - 4 hari', '3 - 5 hari', '4 - 7 hari']],
        'lion' => ['Lion Parcel', 'REG', 11160, ['1 - 2 hari', '1 - 2 hari', '2 - 3 hari', '3 - 4 hari', '4 - 6 hari']],
        'spx' => ['SPX Express', 'REG', 8700, ['2 - 2 hari', '2 - 3 hari', '2 - 4 hari', '3 - 5 hari', '5 - 7 hari']],
    ];

    /** Multiplier per zone: same district, same city, same province, same island, inter-island. */
    private const ZONE_FACTOR = [0.9, 1.0, 1.6, 2.4, 4.2];

    /** Cities couriers price as one metro area. */
    private const METRO = [
        'JAKARTA SELATAN' => 'JABODETABEK', 'JAKARTA PUSAT' => 'JABODETABEK', 'JAKARTA BARAT' => 'JABODETABEK',
        'JAKARTA TIMUR' => 'JABODETABEK', 'JAKARTA UTARA' => 'JABODETABEK', 'TANGERANG' => 'JABODETABEK',
        'TANGERANG SELATAN' => 'JABODETABEK', 'BEKASI' => 'JABODETABEK', 'DEPOK' => 'JABODETABEK', 'BOGOR' => 'JABODETABEK',
        'SURABAYA' => 'GERBANGKERTOSUSILA', 'SIDOARJO' => 'GERBANGKERTOSUSILA',
        'YOGYAKARTA' => 'JOGJA', 'SLEMAN' => 'JOGJA', 'DENPASAR' => 'SARBAGITA', 'BADUNG' => 'SARBAGITA',
    ];

    public static function zone(Region $from, Region $to): int
    {
        $metro = fn (Region $r) => self::METRO[$r->city] ?? $r->city;

        return match (true) {
            $from->id === $to->id => 0,
            $metro($from) === $metro($to) => 1,
            $from->province === $to->province => 2,
            $from->island === $to->island => 3,
            default => 4,
        };
    }

    /** @return array<int, array{code:string,name:string,service:string,cost:int,etd:string,cheapest:bool}> */
    public static function rates(Region $from, Region $to, int $weightGrams): array
    {
        $zone = self::zone($from, $to);
        $kg = max(1, (int) ceil($weightGrams / 1000));
        $rates = [];
        foreach (self::COURIERS as $code => [$name, $service, $base, $etd]) {
            $cost = (int) (round($base * self::ZONE_FACTOR[$zone] * $kg / 100) * 100);
            $rates[] = compact('code', 'name', 'service', 'cost') + ['etd' => $etd[$zone]];
        }
        $min = min(array_column($rates, 'cost'));

        return array_map(fn ($r) => $r + ['cheapest' => $r['cost'] === $min], $rates);
    }

    public static function find(Region $from, Region $to, int $weightGrams, string $code): ?array
    {
        return collect(self::rates($from, $to, $weightGrams))->firstWhere('code', $code);
    }
}
