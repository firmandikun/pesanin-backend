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

    /** Express couriers asked when KiriminAja's courier list is unavailable (KiriminAja codes). */
    public const COURIERS = ['jne', 'sicepat', 'jnt', 'sap', 'idx', 'ninja', 'lion', 'anteraja', 'posindonesia', 'tiki'];

    /** KiriminAja courier code → Pesanin courier code (logos, tracking). */
    public const CODE = ['ide' => 'idexpress', 'idx' => 'idexpress', 'j&t' => 'jnt', 'posindonesia' => 'pos'];

    /** Pesanin courier code → KiriminAja code (pricing, orders). */
    public const KA_CODE = ['idexpress' => 'idx', 'ide' => 'idx', 'pos' => 'posindonesia', 'j&t' => 'jnt'];

    /** Instant couriers (separate pricing/order API, not used for regular parcels). */
    public const INSTANT = ['gosend', 'grab_express', 'borzo'];

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

    /** Remember a Region's KiriminAja district_id (and kelurahan id) so rates/pickup don't need an extra lookup. */
    public function rememberDistrictId(Region $region, int $districtId, int $subdistrictId = 0): void
    {
        if ($region->id && $districtId > 0) {
            Cache::forever("kiriminaja:district:{$this->envTag()}:{$region->id}", $districtId);
        }
        if ($region->id && $subdistrictId > 0) {
            Cache::forever("kiriminaja:subdistrict:{$this->envTag()}:{$region->id}", $subdistrictId);
        }
    }

    /**
     * KiriminAja kelurahan id of a region (required by request_pickup v6.2). From the address search when the region
     * came from it, else the kecamatan's kelurahan list matched by name (first one when the region has no kelurahan).
     */
    public function subdistrictId(Region $region): int
    {
        $key = "kiriminaja:subdistrict:{$this->envTag()}:{$region->id}";
        if ($cached = Cache::get($key)) {
            return (int) $cached;
        }
        $rows = $this->call('post', '/api/mitra/kelurahan', ['kecamatan_id' => $this->districtId($region)]);
        $list = collect($rows['results'] ?? $rows['data'] ?? []);
        $norm = fn ($v) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $v));
        $hit = ($region->subdistrict ? $list->first(fn ($k) => $norm($k['kelurahan_name'] ?? $k['name'] ?? '') === $norm($region->subdistrict)) : null) ?? $list->first();
        $id = (int) ($hit['id'] ?? $hit['kelurahan_id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException("Kelurahan untuk {$region->district}, {$region->city} tidak ditemukan di KiriminAja.");
        }
        Cache::forever($key, $id);

        return $id;
    }

    /** Express courier codes active on this account (KiriminAja codes), cached a day; fallback list on error. */
    public function couriers(): array
    {
        return Cache::remember('kiriminaja:couriers:'.$this->envTag(), now()->addDay(), function () {
            try {
                $rows = collect($this->call('post', '/api/mitra/couriers')['datas'] ?? []);
                $codes = $rows->filter(fn ($c) => strtolower((string) ($c['type'] ?? 'express')) !== 'instant' && ! in_array($c['code'] ?? '', self::INSTANT, true))
                    ->pluck('code')->filter()->map(fn ($c) => strtolower((string) $c))->values()->all();

                return $codes ?: self::COURIERS;
            } catch (\Throwable) {
                return self::COURIERS;
            }
        });
    }

    /** Pickup windows offered by KiriminAja (only ones not expired). @return array<int, array{clock: string, until: ?string, holiday: bool}> */
    public function schedules(): array
    {
        $rows = $this->call('post', '/api/mitra/v2/schedules')['schedules'] ?? [];

        return collect($rows)->filter(fn ($s) => ! empty($s['clock']) && (empty($s['expired']) || now('Asia/Jakarta')->lt(\Illuminate\Support\Carbon::parse(is_numeric($s['expired']) ? '@'.$s['expired'] : $s['expired'], 'Asia/Jakarta'))))
            ->map(fn ($s) => ['clock' => (string) $s['clock'], 'until' => $s['until'] ?? null, 'holiday' => (bool) ($s['libur'] ?? false)])->values()->all();
    }

    /** Cancel an express shipment (before courier pickup). Reason 5–200 chars. */
    public function cancel(string $awb, string $reason): array
    {
        $reason = mb_substr(trim($reason) ?: 'Dibatalkan penjual', 0, 200);

        return $this->call('post', '/api/mitra/v3/cancel_shipment', ['awb' => $awb, 'reason' => mb_strlen($reason) < 5 ? 'Dibatalkan penjual' : $reason]);
    }

    /** Register (replace) the account's webhook callback URL. */
    public function setCallback(string $url): array
    {
        return $this->call('post', '/api/mitra/set_callback', ['url' => $url]);
    }

    /** KA Credit balance (null when the account has none / not supported). */
    public function balance(): ?float
    {
        try {
            $r = $this->call('get', '/api/mitra/v6.2/credit/balance');

            return isset($r['results']['balance']) ? (float) $r['results']['balance'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Percent from KiriminAja's setting strings ("2.5" = 2.5 %, "0.025" = 2.5 %). */
    public static function pct(mixed $v): float
    {
        $f = (float) str_replace(',', '.', (string) $v);

        return $f > 0 && $f < 1 ? $f : $f / 100;
    }

    /**
     * Amounts KiriminAja expects (API overview "Insurance, COD, And Item Price"):
     * insurance = item_value × insurance % (rounded up to 100); COD = item + shipping + insurance + COD fee, the fee
     * being subtotal × COD % rounded up (and at least the courier's minimum COD fee).
     * @return array{insurance: int, cod_fee: int, cod: int}
     */
    public static function amounts(int $itemValue, int $shipping, array $setting, bool $insure, bool $cod): array
    {
        $insurance = $insure ? (int) (ceil($itemValue * self::pct($setting['insurance_fee'] ?? 0) / 100) * 100) + (int) ($setting['insurance_add_cost'] ?? 0) : 0;
        $subtotal = $itemValue + $shipping + $insurance;
        $fee = $cod ? max((int) ceil($subtotal * self::pct($setting['cod_fee'] ?? 0)), (int) ($setting['minimum_cod_fee'] ?? 0)) : 0;

        return ['insurance' => $insurance, 'cod_fee' => $fee, 'cod' => $cod ? $subtotal + $fee : 0];
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
            'subdistrict_id' => (int) ($d['subdistrict_id'] ?? $d['kelurahan_id'] ?? 0),
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
                $this->rememberDistrictId($region, $hit['district_id'], $hit['subdistrict_id'] ?? 0);

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

        $cacheKey = 'kiriminaja:cost:v2:'.$this->envTag().":{$origin}:{$dest}:".(int) ceil($weight / 250);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $res = $this->call('post', '/api/mitra/v6.1/shipping_price', [
            'origin' => $origin,
            'destination' => $dest,
            'weight' => $weight,
            'item_value' => $value,
            'insurance' => 0,
            'courier' => $this->couriers(),
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
                // For the booking modal: drop-off allowed, cut-off, COD / insurance terms of this service.
                'drop' => (bool) ($r['drop'] ?? false),
                'cut_off' => $r['cut_off_time'] ?? null,
                'group' => $r['group'] ?? null,
                'cod_fee_pct' => round(self::pct($r['setting']['cod_fee'] ?? 0) * 100, 2),
                'insurance_pct' => round(self::pct($r['setting']['insurance_fee'] ?? 0) * 100, 2),
                'force_insurance' => (bool) ($r['force_insurance'] ?? false),
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

    /**
     * Create the shipment and request pickup / drop-off (KiriminAja request_pickup v6.2).
     * $opts: insurance (bool), length/width/height (cm), schedule ("Y-m-d H:i:s" from schedules()).
     * @return array{order_id: string, awb: ?string, pickup_number: ?string, shipping_cost: int, insurance: int, cod: int, cod_fee: int, schedule: string, payment_status: ?string, qr_url: ?string}
     */
    public function requestPickup(Order $order, bool $dropoff = false, array $opts = []): array
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
        $raw = strtolower((string) (strtok((string) $order->courier_code, ':') ?: $order->courier));
        $service = self::KA_CODE[$raw] ?? $raw;
        if (! $service || ! $order->courier_service) {
            throw new RuntimeException("Kurir {$order->courier} {$order->courier_service} belum didukung untuk pesan otomatis.");
        }

        $phone = fn (?string $p) => preg_replace('/^\+?62/', '0', preg_replace('/[^\d+]/', '', (string) $p));
        $qty = max(1, (int) $order->quantity);
        $items = max(0, (int) $order->subtotal);
        $cod = $order->payment_method === 'cod';
        $rawTotalWeight = max(1, (int) ($order->weight ?: ($order->product?->weight ?? 1000) * $qty));
        $unitWeight = max(1, (int) round($rawTotalWeight / $qty));
        $weight = $unitWeight * $qty;
        [$length, $width, $height] = [max(1, (int) ($opts['length'] ?? 10)), max(1, (int) ($opts['width'] ?? 10)), max(1, (int) ($opts['height'] ?? 10))];

        $originId = $this->districtId($pickup->region);
        $destId = $this->districtId($dest);
        $itemValue = max(1000, $items);

        // The exact tariff + COD/insurance terms of the chosen service (KiriminAja rejects a different shipping_cost).
        $match = null;
        try {
            $priceRes = $this->call('post', '/api/mitra/v6.1/shipping_price', [
                'origin' => $originId, 'destination' => $destId, 'weight' => $weight, 'length' => $length, 'width' => $width, 'height' => $height,
                'item_value' => $itemValue, 'insurance' => ! empty($opts['insurance']) ? 1 : 0, 'courier' => [$service],
            ]);
            $match = collect($priceRes['results'] ?? $priceRes['data']['results'] ?? [])
                ->first(fn ($r) => strtoupper((string) ($r['service_type'] ?? $r['service'] ?? '')) === strtoupper((string) $order->courier_service));
        } catch (\Throwable) {
            // Fall back to the order's stored shipping cost.
        }
        if ($cod && $match && array_key_exists('cod', $match) && ! $match['cod']) {
            throw new RuntimeException("Layanan {$order->courier} {$order->courier_service} tidak melayani COD ke alamat ini. Pilih kurir lain.");
        }
        if ($dropoff && $match && array_key_exists('drop', $match) && ! $match['drop']) {
            throw new RuntimeException("{$order->courier} tidak menerima drop-off lewat KiriminAja. Pilih Pickup.");
        }
        $shippingCost = (int) ($match['cost'] ?? 0) ?: (int) $order->shipping_cost;
        $insure = ! empty($opts['insurance']) || ! empty($match['force_insurance']);
        $amt = self::amounts($itemValue, $shippingCost, (array) ($match['setting'] ?? []), $insure, $cod);

        // Pickup time: the requested KiriminAja window, else the first open one, else ~2 hours from now in working hours.
        $schedule = $opts['schedule'] ?? null;
        if (! $schedule && ! $dropoff) {
            try {
                $schedule = collect($this->schedules())->reject(fn ($x) => $x['holiday'])->first()['clock'] ?? null;
            } catch (\Throwable) {
                $schedule = null;
            }
        }
        if (! $schedule) {
            $at = now('Asia/Jakarta')->addMinutes(100)->second(0);
            $at->addMinutes((15 - $at->minute % 15) % 15);
            if ($at->hour >= 16 || $at->hour < 8) {
                $at = ($at->hour >= 16 ? $at->addDay() : $at)->setTime(9, 0);
            }
            $schedule = $at->format('Y-m-d H:i:s');
        }

        $prefix = trim((string) config('services.kiriminaja.prefix', ''));
        $kaOrderId = $prefix !== '' && ! str_starts_with((string) $order->code, $prefix) ? "{$prefix}-{$order->code}" : (string) $order->code;
        $name = trim((string) ($pickup->name ?: $order->user->store_name ?: 'Pesanin'));

        $payload = array_filter([
            'address' => (string) $pickup->address,
            'phone' => $phone($pickup->phone ?: $order->user->whatsapp),
            // KiriminAja: sender name must be 8–14 characters.
            'name' => str_pad(mb_substr($name, 0, 14), 8, '.'),
            'kecamatan_id' => $originId,
            'kelurahan_id' => $this->subdistrictId($pickup->region),
            'zipcode' => (string) ($pickup->postal_code ?: $pickup->region->postal_code ?: '00000'),
            // Pesanin has no map pin for addresses yet: KiriminAja places the pickup by kelurahan.
            'latitude' => (float) ($pickup->latitude ?? 0),
            'longitude' => (float) ($pickup->longitude ?? 0),
            'schedule' => $schedule,
            'platform_name' => 'Pesanin',
            // KA Credit pays the shipping right away (KIRIMINAJA_PAYMENT=credit); otherwise KiriminAja's default billing.
            'payment_method' => in_array(config('services.kiriminaja.payment'), ['credit', 'cash'], true) ? config('services.kiriminaja.payment') : null,
            'packages' => [[
                'order_id' => $kaOrderId,
                'destination_name' => (string) $order->customer_name,
                'destination_phone' => $phone($order->phone),
                'destination_address' => trim(implode(', ', array_filter([$order->address, $order->village]))),
                'destination_kecamatan_id' => $destId,
                'destination_kelurahan_id' => $this->subdistrictId($dest),
                'destination_zipcode' => (string) ($order->postal_code ?: $dest->postal_code ?: '00000'),
                'destination_latitude' => 0,
                'destination_longitude' => 0,
                'weight' => $weight, 'width' => $width, 'length' => $length, 'height' => $height, 'qty' => $qty,
                'item_value' => $itemValue,
                'shipping_cost' => $shippingCost,
                'service' => $service,
                'service_type' => (string) $order->courier_service,
                'insurance_amount' => $amt['insurance'],
                'cod' => $amt['cod'],
                'package_type_id' => 7,
                'item_name' => mb_substr((string) ($order->product?->name ?? 'Produk'), 0, 100),
                'drop' => $dropoff,
                'note' => $order->notes ? mb_substr((string) $order->notes, 0, 200) : null,
                'items' => [[
                    'name' => (string) ($order->product?->name ?? 'Produk'),
                    'price' => (int) $order->unit_price,
                    'qty' => $qty, 'weight' => $unitWeight, 'width' => $width, 'length' => $length, 'height' => $height,
                    'metadata' => ['sku' => (string) ($order->product?->sku ?? ''), 'variant_label' => (string) ($order->variant ?? '')],
                ]],
            ]],
        ], fn ($v) => $v !== null);
        $payload['packages'][0] = array_filter($payload['packages'][0], fn ($v) => $v !== null);

        $res = $this->call('post', '/api/mitra/v6.2/request_pickup', $payload);
        $pkg = $res['details'][0] ?? [];

        return [
            'order_id' => (string) ($pkg['kj_order_id'] ?? $pkg['order_id'] ?? $kaOrderId),
            'awb' => ! empty($pkg['awb']) ? (string) $pkg['awb'] : null, // may arrive later via webhook (processed_packages)
            'pickup_number' => ! empty($res['pickup_number']) ? (string) $res['pickup_number'] : null,
            'shipping_cost' => $shippingCost,
            'insurance' => $amt['insurance'],
            'cod' => $amt['cod'],
            'cod_fee' => $amt['cod_fee'],
            'schedule' => $schedule,
            'payment_status' => $res['payment_status'] ?? null,
            'qr_url' => $res['qr_url'] ?? null,
        ];
    }

    /** Track a shipment by order_id or AWB (resi). */
    public function track(string $waybillOrOrderId): array
    {
        $key = 'kiriminaja:track:'.$this->envTag().':'.$waybillOrOrderId;
        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $res = $this->call('post', '/api/mitra/tracking', ['order_id' => $waybillOrOrderId]);
        $data = is_array($res) ? ($res['data'] ?? $res) : [];
        if ($data !== []) {
            Cache::put($key, $data, now()->addMinutes(5));
        }

        return $data;
    }

    /** Map KiriminAja status text or numeric status_code to Pesanin shipping_status. */
    public static function mapShippingStatus(?string $status = null, mixed $statusCode = null): ?string
    {
        $code = is_numeric($statusCode) ? (int) $statusCode : null;
        if ($code === 200) {
            return 'delivered';
        }
        if ($code === 400) {
            return 'returned';
        }
        $s = strtolower(trim((string) $status));
        if ($s === '') {
            return null;
        }

        return match (true) {
            str_contains($s, 'return') || str_contains($s, 'rts') || str_contains($s, 'dikembalikan') => 'returned',
            str_contains($s, 'deliver') && ! str_contains($s, 'on_deliver') && ! str_contains($s, 'on deliver') => 'delivered',
            str_contains($s, 'complet') || str_contains($s, 'finish') || str_contains($s, 'selesai') || str_contains($s, 'diterima') => 'delivered',
            str_contains($s, 'return') || str_contains($s, 'rts') || str_contains($s, 'dikembalikan') => 'returned',
            str_contains($s, 'pickup') || str_contains($s, 'picked') || str_contains($s, 'process') => 'picked_up',
            str_contains($s, 'transit') || str_contains($s, 'on_deliver') || str_contains($s, 'on deliver') || str_contains($s, 'ship') || str_contains($s, 'manifest') => 'in_transit',
            default => null,
        };
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
