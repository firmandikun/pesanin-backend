<?php

namespace App\Support\KiriminAja;

use App\Models\Order;
use App\Models\Region;
use App\Models\UserIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * KiriminAja API (developer.kiriminaja.com, Bearer token).
 * Sandbox: https://tdev.kiriminaja.com | Production: https://client.kiriminaja.com.
 * Used for kelurahan/kecamatan address lookup, shipping rates, pickup booking, and AWB tracking.
 */
class KiriminAja
{
    public const LIVE = 'https://client.kiriminaja.com';

    public const SANDBOX = 'https://tdev.kiriminaja.com';

    /** Couriers asked at checkout (KiriminAja courier slugs). */
    public const COURIERS = ['jne', 'sicepat', 'jnt', 'sap', 'idexpress', 'ninja', 'lion', 'anteraja', 'pos', 'tiki'];

    /** KiriminAja courier code → Pesanin courier code (logos, tracking). */
    public const CODE = ['ide' => 'idexpress', 'j&t' => 'jnt'];

    public function __construct(private UserIntegration $integration) {}

    /** Pesanin's own account from .env (KIRIMINAJA_API_KEY), as an unsaved integration; null when not set. */
    public static function platform(): ?UserIntegration
    {
        $key = config('services.kiriminaja.key');
        if (! $key) {
            return null;
        }
        $sandbox = (bool) config('services.kiriminaja.sandbox', str_starts_with((string) $key, 'v4.local.'));
        $i = new UserIntegration(['provider' => 'kiriminaja', 'status' => 'active']);
        $i->credentials = array_filter([
            'api_key' => $key,
            'password' => config('services.kiriminaja.password'),
        ]);
        $i->settings = [
            'delivery' => [
                'enabled' => (bool) config('services.kiriminaja.delivery', true),
                'sandbox' => $sandbox,
                'vehicle' => 'Motor',
            ],
        ];

        return $i;
    }

    /** KiriminAja runs on Pesanin's .env account. */
    public static function account(?UserIntegration $own = null): ?UserIntegration
    {
        return self::platform();
    }

    public static function for(?UserIntegration $integration): ?self
    {
        return $integration && $integration->provider === 'kiriminaja' && $integration->key('api_key') ? new self($integration) : null;
    }

    private function host(): string
    {
        $sandbox = ! empty($this->integration->settings['delivery']['sandbox'])
            || str_starts_with((string) $this->integration->key('api_key'), 'v4.local.');

        return $sandbox ? self::SANDBOX : self::LIVE;
    }

    private function envTag(): string
    {
        return $this->host() === self::SANDBOX ? 'sbx' : 'live';
    }

    /** @return array<int, array<string, mixed>> */
    public function searchDestinations(string $q): array
    {
        $key = 'kiriminaja:dest:'.$this->envTag().':'.mb_strtolower(trim($q));
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $res = $this->call('get', '/api/mitra/v6.1/addresses', ['search' => $q]);
        $rows = $res['data'] ?? $res['results'] ?? (is_array($res) && array_is_list($res) ? $res : []);
        $rows = is_array($rows) ? $rows : [];
        if ($rows !== []) {
            Cache::put($key, $rows, now()->addDays(30));
        }

        return $rows;
    }

    /** Remember a Region's KiriminAja district_id so rates/pickup don't need an extra lookup. */
    public function rememberDistrictId(Region $region, int $districtId): void
    {
        if ($region->id && $districtId > 0) {
            Cache::forever("kiriminaja:district:{$this->envTag()}:{$region->id}", $districtId);
        }
    }

    /** Parse a KiriminAja address item (supports both `full_address` string and explicit name fields). */
    public static function parseAddress(array $d): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) ($d['full_address'] ?? ''))), fn ($v) => $v !== ''));
        $n = count($parts);
        $postalFromParts = $n >= 2 && preg_match('/^\d{5}$/', $parts[$n - 1]) ? array_pop($parts) : '';
        $n = count($parts);

        $subdistrict = (string) ($d['subdistrict_name'] ?? ($n >= 4 ? $parts[0] : ''));
        $district = (string) ($d['district_name'] ?? ($n >= 4 ? $parts[1] : ($n === 3 ? $parts[0] : ($parts[0] ?? ''))));
        $city = (string) ($d['city_name'] ?? ($n >= 4 ? $parts[2] : ($n === 3 ? $parts[1] : ($parts[1] ?? ''))));
        $province = (string) ($d['province_name'] ?? ($n >= 4 ? $parts[3] : ($n === 3 ? $parts[2] : ($parts[2] ?? ''))));
        $zip = (string) ($d['postal_code'] ?? $d['zip_code'] ?? $postalFromParts);

        return [
            'district_id' => (int) ($d['district_id'] ?? $d['kecamatan_id'] ?? $d['id'] ?? 0),
            'subdistrict' => $subdistrict,
            'district' => $district,
            'city' => $city,
            'province' => $province,
            'postal_code' => ($zip !== '' && $zip !== '0') ? $zip : null,
        ];
    }

    /** KiriminAja district_id for a region (cached permanently per environment + region id). */
    public function districtId(Region $region): int
    {
        $cacheKey = "kiriminaja:district:{$this->envTag()}:{$region->id}";
        if ($cached = Cache::get($cacheKey)) {
            return (int) $cached;
        }

        $norm = fn ($s) => trim(preg_replace('/^(KOTA|KABUPATEN|KAB\.)\s+/', '', mb_strtoupper((string) $s)));
        $cleanCity = $norm($region->city);
        $queries = array_values(array_unique(array_filter([
            $region->subdistrict && $cleanCity ? trim("{$region->subdistrict} {$region->district} {$cleanCity}") : null,
            $cleanCity ? trim("{$region->district} {$cleanCity}") : null,
            $region->postal_code ?: null,
            $region->subdistrict ?: null,
            $region->district ?: null,
        ])));

        foreach ($queries as $kw) {
            $hits = collect($this->searchDestinations((string) $kw))->map(fn ($d) => self::parseAddress($d));
            $hit = $hits->first(fn ($h) => $norm($h['district']) === $norm($region->district)
                && str_contains($norm($h['city']), $norm($region->city))
                && (! $region->postal_code || (string) ($h['postal_code'] ?? '') === (string) $region->postal_code))
                ?? $hits->first(fn ($h) => $norm($h['district']) === $norm($region->district)
                    && str_contains($norm($h['city']), $norm($region->city)))
                ?? ($hits->count() === 1 ? $hits->first() : null);
            if ($hit && $hit['district_id'] > 0) {
                $this->rememberDistrictId($region, $hit['district_id']);

                return $hit['district_id'];
            }
        }

        throw new RuntimeException("Wilayah {$region->district}, {$region->city} tidak ditemukan di KiriminAja.");
    }

    /** @return array<int, array{code:string, courier:string, name:string, service:string, cost:int, etd:string, cod:bool, provider:string}> */
    public function rates(Region $from, Region $to, int $weightGrams, int $itemValue = 10000): array
    {
        $origin = $this->districtId($from);
        $dest = $this->districtId($to);
        $weight = max(1, $weightGrams);
        $value = max(1000, $itemValue);

        $cacheKey = 'kiriminaja:cost:'.$this->envTag().":{$origin}:{$dest}:".(int) ceil($weight / 250);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $res = $this->call('post', '/api/mitra/v6.1/shipping_price', [
            'origin' => $origin,
            'destination' => $dest,
            'weight' => $weight,
            'item_value' => $value,
            'insurance' => 0,
            'courier' => self::COURIERS,
        ]);

        $rows = $res['results'] ?? $res['data']['results'] ?? $res['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $rates = collect($rows)->map(function ($r) {
            $rawCode = strtolower((string) ($r['courier'] ?? $r['service'] ?? ''));
            $courier = self::CODE[$rawCode] ?? $rawCode;
            $service = (string) ($r['service_type'] ?? $r['service'] ?? 'REG');
            $etdRaw = trim(str_ireplace(['day', 'days', 'hari'], '', (string) ($r['etd'] ?? $r['duration'] ?? '')));

            return [
                'code' => $courier.':'.strtolower(str_replace(' ', '_', $service)),
                'courier' => $courier,
                'name' => self::shortName($courier, (string) ($r['courier_name'] ?? $r['name'] ?? '')),
                'service' => $service,
                'cost' => (int) ($r['cost'] ?? $r['price'] ?? 0),
                'etd' => $etdRaw !== '' ? "{$etdRaw} hari" : '-',
                'cod' => (bool) ($r['cod'] ?? false),
                'provider' => 'kiriminaja',
            ];
        })->filter(fn ($r) => $r['cost'] > 0 && $r['courier'] !== '')
            // Up to two choices per courier: the cheapest and, if different, the fastest.
            ->groupBy('courier')->flatMap(function ($g) {
                $cheap = $g->sortBy('cost')->first();
                $fast = $g->sortBy(fn ($r) => (int) $r['etd'] ?: 99)->first();

                return $fast['code'] !== $cheap['code'] && (int) $fast['etd'] < (int) $cheap['etd'] ? [$cheap, $fast] : [$cheap];
            })->sortBy('cost')->values()->all();

        if ($rates !== []) {
            Cache::put($cacheKey, $rates, now()->addHours(6));
        }

        return $rates;
    }

    /** Create order & request courier pickup/dropoff via KiriminAja v6.2. @return array{order_id: string, awb: ?string, pickup_number: ?string} */
    public function requestPickup(Order $order, bool $dropoff = false): array
    {
        $order->loadMissing('user', 'product');
        $pickup = $order->user->pickupAddress();
        if (! $pickup?->region) {
            throw new RuntimeException('Alamat pickup belum diisi (Pengaturan → Alamat).');
        }
        $dest = $order->region_id ? Region::find($order->region_id) : null;
        if (! $dest) {
            throw new RuntimeException('Alamat pembeli belum lengkap (kecamatan tujuan kosong).');
        }
        $rawCourier = strtolower((string) (strtok((string) $order->courier_code, ':') ?: $order->courier));
        $service = match ($rawCourier) {
            'j&t', 'jnt' => 'jnt',
            'ide', 'idexpress' => 'idexpress',
            default => $rawCourier,
        };
        if (! $service || ! $order->courier_service) {
            throw new RuntimeException("Kurir {$order->courier} {$order->courier_service} belum didukung untuk pesan otomatis.");
        }

        $phone = fn (?string $p) => preg_replace('/^\+?62/', '0', preg_replace('/[^\d+]/', '', (string) $p));
        $qty = max(1, (int) $order->quantity);
        $items = max(0, (int) $order->subtotal);
        $cod = $order->payment_method === 'cod';
        $grand = $items + (int) $order->shipping_cost;
        $rawTotalWeight = max(1, (int) ($order->weight ?: ($order->product?->weight ?? 1000) * $qty));
        $unitWeight = max(1, (int) round($rawTotalWeight / $qty));
        $weight = $unitWeight * $qty;

        $at = now('Asia/Jakarta')->addMinutes(100)->second(0);
        $at->addMinutes((15 - $at->minute % 15) % 15);
        if ($at->hour >= 17 || $at->hour < 8) {
            $at = ($at->hour >= 17 ? $at->addDay() : $at)->setTime(9, 0);
        }

        $originId = $this->districtId($pickup->region);
        $destId = $this->districtId($dest);
        $itemValue = max(1000, $items);

        // KiriminAja rejects request_pickup ("Ongkir tidak sesuai") if shipping_cost differs from their tariff
        // (e.g. when the seller applied a shipping discount or extra fee at checkout). Match their exact cost.
        $kaShippingCost = (int) $order->shipping_cost;
        try {
            $priceRes = $this->call('post', '/api/mitra/v6.1/shipping_price', [
                'origin' => $originId,
                'destination' => $destId,
                'weight' => $weight,
                'item_value' => $itemValue,
                'insurance' => 0,
                'courier' => [$service],
            ]);
            $priceRows = $priceRes['results'] ?? $priceRes['data']['results'] ?? $priceRes['data'] ?? [];
            if (is_array($priceRows)) {
                $matched = collect($priceRows)->first(fn ($r) => strtoupper((string) ($r['service_type'] ?? $r['service'] ?? '')) === strtoupper((string) $order->courier_service));
                if ($matched && (int) ($matched['cost'] ?? 0) > 0) {
                    $kaShippingCost = (int) $matched['cost'];
                }
            }
        } catch (\Throwable) {
            // Fall back to order's stored shipping_cost if tariff lookup fails.
        }

        $prefix = trim((string) config('services.kiriminaja.prefix', ''));
        $kaOrderId = $prefix !== '' && ! str_starts_with((string) $order->code, $prefix)
            ? "{$prefix}-{$order->code}"
            : (string) $order->code;

        $payload = [
            'address' => (string) $pickup->address,
            'phone' => $phone($pickup->phone ?: $order->user->whatsapp),
            'name' => (string) ($pickup->name ?: $order->user->store_name ?: 'Pesanin'),
            'kecamatan_id' => $originId,
            'schedule' => $at->format('Y-m-d H:i:s'),
            'drop' => $dropoff,
            'packages' => [[
                'order_id' => $kaOrderId,
                'destination_name' => (string) $order->customer_name,
                'destination_phone' => $phone($order->phone),
                'destination_address' => trim(implode(', ', array_filter([$order->address, $order->village]))),
                'destination_kecamatan_id' => $destId,
                'weight' => $weight,
                'width' => 10,
                'length' => 10,
                'height' => 10,
                'item_value' => $itemValue,
                'shipping_cost' => $kaShippingCost,
                'service' => $service,
                'service_type' => (string) $order->courier_service,
                'cod' => $cod ? $grand : 0,
                'package_type_id' => 7,
                'item_name' => (string) ($order->product?->name ?? 'Produk'),
                'items' => [[
                    'name' => (string) ($order->product?->name ?? 'Produk'),
                    'price' => (int) $order->unit_price,
                    'qty' => $qty,
                    'weight' => $unitWeight,
                    'width' => 10,
                    'length' => 10,
                    'height' => 10,
                    'metadata' => [
                        'sku' => (string) ($order->product?->sku ?? ''),
                        'variant_label' => (string) ($order->variant ?? ''),
                    ],
                ]],
            ]],
        ];

        $res = $this->call('post', '/api/mitra/v6.2/request_pickup', $payload);
        $data = $res['data'] ?? $res;
        $pkg = $res['details'][0] ?? (is_array($data) && array_is_list($data) ? ($data[0] ?? []) : ($data['packages'][0] ?? $data));
        $orderId = (string) ($pkg['kj_order_id'] ?? $pkg['order_id'] ?? $data['order_id'] ?? $kaOrderId);
        $awb = $pkg['awb'] ?? $pkg['receipt'] ?? $data['receipt'] ?? $data['awb'] ?? null;

        return [
            'order_id' => $orderId,
            'awb' => $awb ? (string) $awb : null,
            'pickup_number' => ! empty($res['pickup_number']) ? (string) $res['pickup_number'] : null,
        ];
    }

    /** Track a shipment by order_id or AWB (resi). */
    public function track(string $waybillOrOrderId): array
    {
        $res = $this->call('post', '/api/mitra/tracking', ['order_id' => $waybillOrOrderId]);

        return is_array($res) ? ($res['data'] ?? $res) : [];
    }

    /** Connection check: one small address search. */
    public function verify(): void
    {
        $this->call('get', '/api/mitra/v6.1/addresses', ['search' => 'gambir']);
    }

    private static function shortName(string $code, string $name): string
    {
        return [
            'jne' => 'JNE', 'jnt' => 'J&T', 'sicepat' => 'SiCepat', 'sap' => 'SAP',
            'idexpress' => 'iDexpress', 'ninja' => 'Ninja', 'lion' => 'Lion Parcel',
            'anteraja' => 'Anteraja', 'pos' => 'POS Indonesia', 'tiki' => 'TIKI',
            'jtcargo' => 'J&T Cargo',
        ][$code] ?? ($name ?: strtoupper($code));
    }

    private function call(string $method, string $path, array $data = []): array
    {
        $req = Http::withToken((string) $this->integration->key('api_key'))
            ->acceptJson()
            ->timeout(25);
        $res = $method === 'post'
            ? $req->asJson()->post($this->host().$path, $data)
            : $req->get($this->host().$path, $data);

        $json = $res->json() ?? [];
        if ($res->failed() || (isset($json['status']) && $json['status'] === false)) {
            $code = (int) ($json['code'] ?? $json['rc'] ?? $res->status());
            if ($code === 404 || ($json['code'] ?? null) === 2) {
                return [];
            }
            $msg = $json['text'] ?? $json['message'] ?? "HTTP {$res->status()}";
            throw new RuntimeException('KiriminAja: '.$msg);
        }

        return is_array($json) ? $json : [];
    }
}
