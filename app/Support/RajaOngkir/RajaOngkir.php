<?php

namespace App\Support\RajaOngkir;

use App\Models\Region;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * RajaOngkir by Komerce (rajaongkir.komerce.id, API key from collaborator.komerce.id → API Key,
 * sent in the `key` header). Used for kelurahan-level destination search, shipping cost and AWB tracking.
 * The free Starter plan has a daily hit quota, so destinations and rates are cached.
 */
class RajaOngkir
{
    public const HOST = 'https://rajaongkir.komerce.id/api/v1';

    /** Couriers asked at checkout (RajaOngkir codes, colon separated). */
    public const COURIERS = 'jne:sicepat:jnt:sap:ide:ninja:lion:anteraja:pos:tiki';

    /** RajaOngkir courier code → Pesanin/Biteship courier code (logos, booking). */
    public const CODE = ['ide' => 'idexpress', 'jnt' => 'jnt', 'sicepat' => 'sicepat'];

    public function __construct(private UserIntegration $integration) {}

    public static function for(?UserIntegration $integration): ?self
    {
        return $integration && $integration->provider === 'rajaongkir' && $integration->key('api_key') ? new self($integration) : null;
    }

    /** @return array<int, array{id:int,label:string,subdistrict_name:string,district_name:string,city_name:string,province_name:string,zip_code:string}> */
    public function searchDestinations(string $q, int $limit = 100): array
    {
        return Cache::remember('rajaongkir:dest:'.mb_strtolower($q), now()->addDays(30),
            fn () => $this->call('get', '/destination/domestic-destination', ['search' => $q, 'limit' => $limit, 'offset' => 0]) ?? []);
    }

    /** RajaOngkir destination id for a region (looked up once by kelurahan/kecamatan + city, then stored). */
    public function destinationId(Region $region): int
    {
        if ($region->rajaongkir_id) {
            return (int) $region->rajaongkir_id;
        }
        $norm = fn ($s) => trim(preg_replace('/^(KOTA|KABUPATEN|KAB\.)\s+/', '', mb_strtoupper((string) $s)));
        $hits = $this->searchDestinations($region->subdistrict ?: $region->district, 50);
        $hit = collect($hits)->first(fn ($h) => $norm($h['district_name']) === $norm($region->district) && str_contains($norm($h['city_name']), $norm($region->city))
            && (! $region->postal_code || $h['zip_code'] === $region->postal_code))
            ?? collect($hits)->first(fn ($h) => $norm($h['district_name']) === $norm($region->district) && str_contains($norm($h['city_name']), $norm($region->city)));
        if (! $hit) {
            throw new RuntimeException("Wilayah {$region->district}, {$region->city} tidak ditemukan di RajaOngkir.");
        }
        if (! Region::where('rajaongkir_id', $hit['id'])->exists()) {
            $region->forceFill(['rajaongkir_id' => $hit['id']])->save();
        }

        return (int) $hit['id'];
    }

    /** @return array<int, array{code:string, courier:string, name:string, service:string, cost:int, etd:string, cod:bool, provider:string}> */
    public function rates(Region $from, Region $to, int $weightGrams): array
    {
        $origin = $this->destinationId($from);
        $dest = $this->destinationId($to);
        $weight = max(1, $weightGrams);

        return Cache::remember("rajaongkir:cost:{$this->integration->id}:{$origin}:{$dest}:".(int) ceil($weight / 250), now()->addHours(6), function () use ($origin, $dest, $weight) {
            $rows = $this->call('post', '/calculate/domestic-cost', [
                'origin' => $origin, 'destination' => $dest, 'weight' => $weight, 'courier' => self::COURIERS, 'price' => 'lowest',
            ]) ?? [];

            return collect($rows)->map(function ($r) {
                $courier = self::CODE[$r['code']] ?? $r['code'];

                return [
                    'code' => $courier.':'.strtolower(str_replace(' ', '_', $r['service'])),
                    'courier' => $courier,
                    'name' => self::shortName($r['code'], $r['name'] ?? ''),
                    'service' => $r['service'],
                    'cost' => (int) $r['cost'],
                    'etd' => trim(str_ireplace(['day', 'days', 'hari'], '', (string) ($r['etd'] ?? ''))) ? trim(str_ireplace(['days', 'day', 'hari'], '', (string) $r['etd'])).' hari' : '-',
                    'cod' => false, // RajaOngkir quotes don't say whether COD is available
                    'provider' => 'rajaongkir',
                ];
            })->filter(fn ($r) => $r['cost'] > 0)
                // Up to two choices per courier: the cheapest and, if different, the fastest.
                ->groupBy('courier')->flatMap(function ($g) {
                    $cheap = $g->sortBy('cost')->first();
                    $fast = $g->sortBy(fn ($r) => (int) $r['etd'] ?: 99)->first();

                    return $fast['code'] !== $cheap['code'] && (int) $fast['etd'] < (int) $cheap['etd'] ? [$cheap, $fast] : [$cheap];
                })->sortBy('cost')->values()->all();
        });
    }

    public function track(string $waybill, string $courier, ?string $phone = null): array
    {
        $code = array_search($courier, self::CODE, true) ?: $courier;

        return $this->call('post', '/track/waybill', array_filter([
            'awb' => $waybill, 'courier' => $code, 'last_phone_number' => $phone ? substr(preg_replace('/\D/', '', $phone), -5) : null,
        ])) ?? [];
    }

    /** Quota-free-ish check for "Tes koneksi": one small destination search. */
    public function verify(): void
    {
        $this->call('get', '/destination/domestic-destination', ['search' => 'gambir', 'limit' => 1, 'offset' => 0]);
    }

    private static function shortName(string $code, string $name): string
    {
        return ['jne' => 'JNE', 'jnt' => 'J&T', 'sicepat' => 'SiCepat', 'sap' => 'SAP', 'ide' => 'iDexpress', 'ninja' => 'Ninja', 'lion' => 'Lion Parcel',
            'anteraja' => 'Anteraja', 'pos' => 'POS Indonesia', 'tiki' => 'TIKI'][$code] ?? $name;
    }

    private function call(string $method, string $path, array $data = []): mixed
    {
        $req = Http::withHeaders(['key' => $this->integration->key('api_key')])->acceptJson()->timeout(20);
        $res = $method === 'post' ? $req->asForm()->post(self::HOST.$path, $data) : $req->get(self::HOST.$path, $data);
        $meta = $res->json('meta') ?? [];
        if ($res->failed() || ($meta['status'] ?? 'success') !== 'success') {
            // "Data not found" on a search is not an error.
            if ((int) ($meta['code'] ?? $res->status()) === 404) {
                return [];
            }
            throw new RuntimeException('RajaOngkir: '.($meta['message'] ?? "HTTP {$res->status()}"));
        }

        return $res->json('data');
    }
}
