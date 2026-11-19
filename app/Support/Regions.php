<?php

namespace App\Support;

use App\Models\Region;
use App\Models\UserIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Kota / Kecamatan" search. With the seller's RajaOngkir key every kelurahan in Indonesia (with kode pos) is
 * found and stored as a region; without it the search uses Pesanin's own region table only.
 */
class Regions
{
    /** Province → island group used by the local rate estimator. */
    private const ISLAND = [
        'Sumatera' => ['ACEH', 'SUMATERA UTARA', 'SUMATERA BARAT', 'RIAU', 'KEPULAUAN RIAU', 'JAMBI', 'SUMATERA SELATAN', 'BANGKA BELITUNG', 'KEPULAUAN BANGKA BELITUNG', 'BENGKULU', 'LAMPUNG'],
        'Jawa' => ['DKI JAKARTA', 'JAWA BARAT', 'BANTEN', 'JAWA TENGAH', 'DI YOGYAKARTA', 'DAERAH ISTIMEWA YOGYAKARTA', 'JAWA TIMUR'],
        'Bali & Nusa Tenggara' => ['BALI', 'NUSA TENGGARA BARAT', 'NUSA TENGGARA TIMUR'],
        'Kalimantan' => ['KALIMANTAN BARAT', 'KALIMANTAN TENGAH', 'KALIMANTAN SELATAN', 'KALIMANTAN TIMUR', 'KALIMANTAN UTARA'],
        'Sulawesi' => ['SULAWESI UTARA', 'GORONTALO', 'SULAWESI TENGAH', 'SULAWESI BARAT', 'SULAWESI SELATAN', 'SULAWESI TENGGARA'],
        'Maluku & Papua' => ['MALUKU', 'MALUKU UTARA', 'PAPUA', 'PAPUA BARAT', 'PAPUA BARAT DAYA', 'PAPUA SELATAN', 'PAPUA TENGAH', 'PAPUA PEGUNUNGAN'],
    ];

    public static function search(string $q, ?UserIntegration $rajaongkir = null): Collection
    {
        $q = trim($q);
        // RajaOngkir (Komerce): every kelurahan in Indonesia with its kode pos.
        if ($ro = \App\Support\RajaOngkir\RajaOngkir::for($rajaongkir)) {
            try {
                return self::fromRajaOngkir($ro, $q);
            } catch (Throwable $e) {
                Log::warning("RajaOngkir destination search '{$q}': {$e->getMessage()}");
            }
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        return Region::where(fn ($w) => $w->where('district', 'like', $like)->orWhere('city', 'like', $like)->orWhere('postal_code', 'like', $like))
            ->orderByRaw('district LIKE ? DESC', [str_replace(['%', '_'], ['\%', '\_'], $q).'%'])
            ->orderBy('province')->orderBy('city')->orderBy('district')->limit(30)->get();
    }

    /** Destinations from RajaOngkir, stored as kelurahan-level regions so checkout/rates can use them. */
    private static function fromRajaOngkir(\App\Support\RajaOngkir\RajaOngkir $ro, string $q): Collection
    {
        return collect($ro->searchDestinations($q))->map(function ($d) {
            $province = self::clean($d['province_name'] ?? '');
            $region = Region::firstOrNew(['rajaongkir_id' => $d['id']]);
            $region->forceFill([
                'province' => $province,
                'city' => self::clean($d['city_name'] ?? '', true),
                'district' => self::clean($d['district_name'] ?? ''),
                'subdistrict' => self::clean($d['subdistrict_name'] ?? '') ?: null,
                'postal_code' => ($d['zip_code'] ?? '') && $d['zip_code'] !== '0' ? (string) $d['zip_code'] : null,
                'island' => self::island($province),
            ]);
            if ($region->isDirty()) {
                $region->save();
            }

            return $region;
        })
            // RajaOngkir matches any word; put kelurahan/kecamatan/kota that contain the whole phrase first.
            ->sortBy(function (Region $r) use ($q) {
                $phrase = Str::upper($q);
                $fields = [$r->subdistrict, $r->district, $r->city, $r->postal_code];

                return match (true) {
                    in_array($phrase, $fields, true) => 0,
                    collect($fields)->contains(fn ($f) => $f && str_starts_with($f, $phrase)) => 1,
                    collect($fields)->contains(fn ($f) => $f && str_contains($f, $phrase)) => 2,
                    default => 3,
                };
            })->take(30)->values();
    }

    private static function clean(string $s, bool $city = false): string
    {
        $s = Str::of($s)->upper()->squish()->value();
        if ($city) {
            $s = preg_replace('/^KOTA (ADMINISTRASI )?/', '', $s);
            $s = preg_replace('/^KABUPATEN (ADMINISTRASI )?/', 'KAB. ', $s);
        }

        return $s;
    }

    public static function island(string $province): string
    {
        foreach (self::ISLAND as $island => $provinces) {
            foreach ($provinces as $p) {
                // Providers spell some differently, e.g. "NANGGROE ACEH DARUSSALAM (NAD)", "DI YOGYAKARTA".
                if ($province === $p || str_contains($province, $p)) {
                    return $island;
                }
            }
        }

        return match (true) {
            str_contains($province, 'ACEH') => 'Sumatera',
            str_contains($province, 'YOGYA') => 'Jawa',
            str_contains($province, 'PAPUA') => 'Maluku & Papua',
            default => 'Jawa',
        };
    }
}
