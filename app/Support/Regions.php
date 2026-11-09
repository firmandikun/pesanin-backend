<?php

namespace App\Support;

use App\Models\Region;
use App\Models\UserIntegration;
use App\Support\Biteship\Biteship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Kota / Kecamatan" search. Pesanin's own table only covers part of Indonesia, so every search also
 * asks Biteship's area API (all kecamatan + kode pos) and stores what it finds as regions, already
 * linked to their Biteship area id for real rates. Without a Biteship key it searches locally only.
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

    public static function search(string $q, ?UserIntegration $biteship = null, ?UserIntegration $rajaongkir = null): Collection
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
        $client = Biteship::for($biteship);
        // Biteship bills area lookups (Rp2/hit, even in testing): only ask when local data is thin
        // and the term is specific enough (4+ characters), and remember each term for a day.
        $known = Region::where(fn ($w) => $w->where('district', 'like', "%{$q}%")->orWhere('city', 'like', "%{$q}%")->orWhere('postal_code', 'like', "{$q}%"))->count();
        if ($client && mb_strlen($q) >= 4 && $known < 3) {
            try {
                self::importFromBiteship($client, $q);
            } catch (Throwable $e) {
                Log::warning("Biteship area search '{$q}': {$e->getMessage()}");
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

    /** One Biteship lookup per search term per day; results become regions. */
    private static function importFromBiteship(Biteship $client, string $q): void
    {
        $key = 'regions:biteship:'.Str::lower($q);
        if (Cache::has($key)) {
            return;
        }
        foreach ($client->searchAreas($q) as $a) {
            $province = self::clean($a['administrative_division_level_1_name'] ?? '');
            $city = self::clean($a['administrative_division_level_2_name'] ?? '', true);
            $district = self::clean($a['administrative_division_level_3_name'] ?? '');
            if (! $province || ! $city || ! $district) {
                continue;
            }
            $postal = (string) ($a['postal_code'] ?? '') ?: null;
            $region = Region::where('biteship_area_id', $a['id'])->first()
                ?? Region::whereNull('biteship_area_id')->where('city', $city)->where('district', $district)->first()
                ?? new Region(['province' => $province, 'city' => $city, 'district' => $district, 'island' => self::island($province)]);
            $region->forceFill(['biteship_area_id' => $a['id'], 'postal_code' => $region->postal_code ?: $postal])->save();
        }
        Cache::put($key, true, now()->addDay());
    }

    /** "Kota Bandung" → "BANDUNG", "Kabupaten Bandung" → "KAB. BANDUNG" (kept apart from the city). */
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
                // Biteship spells some differently, e.g. "NANGGROE ACEH DARUSSALAM (NAD)", "DI YOGYAKARTA".
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
