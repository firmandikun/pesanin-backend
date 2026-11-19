<?php

namespace App\Support\RajaOngkir;

use App\Models\Order;
use App\Models\Region;
use App\Models\UserIntegration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Komerce Delivery API ("Komship", same company and same Shipping API key as RajaOngkir): book a web/manual order
 * with the courier the buyer chose, request the pickup and receive the real waybill (resi).
 * Flow: destination ids → POST /order/api/v1/orders/store (order_no KOM…) → POST /order/api/v1/pickup/request (awb).
 * Docs: rajaongkir.com/docs/delivery-order-api. Non-COD shipping is paid from the seller's Komerce balance.
 */
class KomerceDelivery
{
    public const LIVE = 'https://api.collaborator.komerce.id';

    public const SANDBOX = 'https://api-sandbox.collaborator.komerce.id';

    /** Pesanin/RajaOngkir courier code → Komship "shipping" name. */
    public const SHIPPING = ['jne' => 'JNE', 'sicepat' => 'SICEPAT', 'sap' => 'SAP', 'idexpress' => 'IDEXPRESS', 'ide' => 'IDEXPRESS', 'jnt' => 'J&T', 'ninja' => 'NINJA', 'lion' => 'LION', 'anteraja' => 'ANTERAJA', 'pos' => 'POS', 'tiki' => 'TIKI'];

    public function __construct(private UserIntegration $integration) {}

    /** Seller's RajaOngkir integration with booking switched on (Pengaturan → Integrasi). */
    public static function for(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'rajaongkir' && ($i->key('delivery_key') ?: $i->key('api_key')) && ! empty($i->settings['delivery']['enabled']) ? new self($i) : null;
    }

    private function host(): string
    {
        return ! empty($this->integration->settings['delivery']['sandbox']) ? self::SANDBOX : self::LIVE;
    }

    /** Komship destination id for a region (postal code first, else kecamatan + kota), remembered per region. */
    public function destinationId(Region $region): int
    {
        $env = ! empty($this->integration->settings['delivery']['sandbox']) ? 'sbx' : 'live';

        return (int) Cache::rememberForever("komship:dest:{$env}:{$region->id}", function () use ($region) {
            $norm = fn ($s) => trim(preg_replace('/^(KOTA|KABUPATEN|KAB\.)\s+/', '', mb_strtoupper((string) $s)));
            foreach (array_filter([$region->postal_code, trim("{$region->subdistrict} {$region->district}"), $region->district]) as $kw) {
                $hits = $this->call('get', '/tariff/api/v1/destination/search', ['keyword' => $kw]) ?? [];
                $hit = collect($hits)->first(fn ($h) => $norm($h['district_name'] ?? '') === $norm($region->district) && str_contains($norm($h['city_name'] ?? ''), $norm($region->city)))
                    ?? (count($hits) === 1 ? $hits[0] : null);
                if ($hit) {
                    return (int) $hit['id'];
                }
            }
            throw new RuntimeException("Wilayah {$region->district}, {$region->city} tidak ditemukan di Komerce.");
        });
    }

    /** Create the Komship order for a Pesanin order. @return string order_no (KOM…) */
    public function store(Order $order): string
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
        $courier = strtok((string) $order->courier_code, ':') ?: strtolower((string) $order->courier);
        $shipping = self::SHIPPING[$courier] ?? null;
        if (! $shipping || ! $order->courier_service) {
            throw new RuntimeException("Kurir {$order->courier} {$order->courier_service} belum didukung untuk pesan otomatis.");
        }
        $phone = fn (?string $p) => preg_replace('/^\+?62/', '0', preg_replace('/[^\d+]/', '', (string) $p));
        $items = max(0, (int) $order->subtotal);
        $cod = $order->payment_method === 'cod';
        $grand = $items + (int) $order->shipping_cost;
        $weight = max(1, (int) ($order->weight ?: ($order->product?->weight ?? 1000) * max(1, (int) $order->quantity)));

        $payload = [
            'order_date' => Carbon::parse($order->created_at)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
            'brand_name' => (string) ($order->user->store_name ?: 'Pesanin'),
            'shipper_name' => (string) ($pickup->name ?: $order->user->store_name),
            'shipper_phone' => $phone($pickup->phone ?: $order->user->whatsapp),
            'shipper_destination_id' => $this->destinationId($pickup->region),
            'shipper_address' => (string) $pickup->address,
            'shipper_email' => (string) $order->user->email,
            'receiver_name' => (string) $order->customer_name,
            'receiver_phone' => $phone($order->phone),
            'receiver_destination_id' => $this->destinationId($dest),
            'receiver_address' => trim(implode(', ', array_filter([$order->address, $order->village]))),
            'receiver_email' => (string) ($order->email ?? ''),
            'shipping' => $shipping,
            'shipping_type' => strtoupper((string) $order->courier_service),
            'shipping_cost' => (int) $order->shipping_cost,
            'shipping_cashback' => 0,
            'payment_method' => $cod ? 'COD' : 'BANK TRANSFER',
            'service_fee' => $cod ? (int) round($grand * 0.028) : 0,
            'additional_cost' => 0,
            'grand_total' => $grand,
            'cod_value' => $cod ? $grand : 0,
            'insurance_value' => 0,
            'notes' => mb_substr((string) $order->notes, 0, 200),
            'order_details' => [[
                'product_name' => (string) ($order->product?->name ?? 'Produk'),
                'product_variant_name' => (string) ($order->variant ?? ''),
                'product_price' => (int) $order->unit_price,
                'product_weight' => $weight,
                'product_width' => 10, 'product_height' => 10, 'product_length' => 10,
                'qty' => max(1, (int) $order->quantity),
                'subtotal' => $items,
            ]],
        ];
        $data = $this->call('post', '/order/api/v1/orders/store', $payload);
        if (empty($data['order_no'])) {
            throw new RuntimeException('Komerce tidak mengembalikan nomor order.');
        }

        return (string) $data['order_no'];
    }

    /**
     * Ask the courier to pick up. Komerce needs the pickup ≥ 90 minutes after now; after 17:00 WIB it goes to 09:00 the
     * next day. @return array<string, string> order_no => awb (only those that got one)
     */
    public function pickup(array $orderNos): array
    {
        $at = now('Asia/Jakarta')->addMinutes(100)->second(0);
        $at->addMinutes((15 - $at->minute % 15) % 15);
        if ($at->hour >= 17 || $at->hour < 8) {
            $at = ($at->hour >= 17 ? $at->addDay() : $at)->setTime(9, 0);
        }
        $vehicle = $this->integration->settings['delivery']['vehicle'] ?? 'Motor';
        $rows = $this->call('post', '/order/api/v1/pickup/request', [
            'pickup_date' => $at->format('Y-m-d'), 'pickup_time' => $at->format('H:i'), 'pickup_vehicle' => $vehicle,
            'orders' => array_map(fn ($n) => ['order_no' => $n], array_values($orderNos)),
        ]) ?? [];

        return collect($rows)->filter(fn ($r) => ($r['status'] ?? '') === 'success' && ! empty($r['awb']))->mapWithKeys(fn ($r) => [$r['order_no'] => $r['awb']])->all();
    }

    public function detail(string $orderNo): array
    {
        return $this->call('get', '/order/api/v1/orders/detail', ['order_no' => $orderNo]) ?? [];
    }

    /** "Tes koneksi" for booking: one destination search on the Delivery API. */
    public function verify(): void
    {
        $this->call('get', '/tariff/api/v1/destination/search', ['keyword' => '10110']);
    }

    private function call(string $method, string $path, array $data = []): mixed
    {
        // The "Shipping Delivery" key (a different key from "Shipping Cost").
        $req = Http::withHeaders(['x-api-key' => $this->integration->key('delivery_key') ?: $this->integration->key('api_key')])->acceptJson()->timeout(25);
        $res = $method === 'post' ? $req->asJson()->post($this->host().$path, $data) : $req->get($this->host().$path, $data);
        $meta = $res->json('meta') ?? [];
        if ($res->failed() || ! in_array(strtolower((string) ($meta['status'] ?? 'success')), ['success'], true)) {
            $errors = collect($res->json('errors') ?? $res->json('data.errors') ?? [])->flatten()->filter()->implode('; ');
            throw new RuntimeException('Komerce: '.($meta['message'] ?? "HTTP {$res->status()}").($errors ? " ({$errors})" : ''));
        }

        return $res->json('data');
    }
}
