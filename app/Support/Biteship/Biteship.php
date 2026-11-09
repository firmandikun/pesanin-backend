<?php

namespace App\Support\Biteship;

use App\Models\Order;
use App\Models\Region;
use App\Models\UserIntegration;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Biteship (api.biteship.com) with the seller's own API key: real courier rates, COD,
 * booking (order → waybill), tracking. Test keys ("biteship_test…") hit the same host in test mode.
 */
class Biteship
{
    public const HOST = 'https://api.biteship.com';

    /** Couriers offered at checkout (Biteship codes). */
    public const COURIERS = 'jne,jnt,sicepat,sap,anteraja,idexpress,ninja,lion,pos,tiki';

    /** Biteship order status → Pesanin shipping_status. */
    public const STATUS = [
        'confirmed' => 'not_shipped', 'scheduled' => 'not_shipped', 'allocated' => 'not_shipped', 'picking_up' => 'not_shipped',
        'picked' => 'picked_up', 'dropping_off' => 'in_transit', 'on_hold' => 'in_transit',
        'delivered' => 'delivered', 'return_in_transit' => 'returned', 'returned' => 'returned',
        'rejected' => 'not_shipped', 'courier_not_found' => 'not_shipped', 'cancelled' => 'not_shipped', 'disposed' => 'returned',
    ];

    public function __construct(private UserIntegration $integration) {}

    public static function for(?UserIntegration $integration): ?self
    {
        return $integration && $integration->provider === 'biteship' && $integration->key('api_key') ? new self($integration) : null;
    }

    /** Cheap authenticated call used by "Tes koneksi". */
    public function couriers(): array
    {
        return $this->call('get', '/v1/couriers')['couriers'] ?? [];
    }

    /** Kecamatan-level areas matching free text (district, city or postal code). */
    public function searchAreas(string $input): array
    {
        return $this->call('get', '/v1/maps/areas', ['countries' => 'ID', 'input' => $input, 'type' => 'single'])['areas'] ?? [];
    }

    /** Biteship area id for a Pesanin region (district level), cached on the region row. */
    public function areaId(Region $region): string
    {
        if ($region->biteship_area_id) {
            return $region->biteship_area_id;
        }
        $input = trim("{$region->district}, {$region->city}, {$region->province}", ', ');
        $areas = $this->call('get', '/v1/maps/areas', ['countries' => 'ID', 'input' => $input, 'type' => 'single'])['areas'] ?? [];
        $norm = fn ($s) => Str::of((string) $s)->upper()->replace(['KABUPATEN ', 'KOTA ', 'KAB. '], '')->squish()->value();
        $best = collect($areas)->first(fn ($a) => $norm($a['administrative_division_level_3_name'] ?? '') === $norm($region->district)
            && str_contains($norm($a['administrative_division_level_2_name'] ?? ''), $norm($region->city))) ?? ($areas[0] ?? null);
        if (! $best) {
            throw new BiteshipException("Wilayah {$input} tidak ditemukan di Biteship.");
        }
        $region->forceFill(['biteship_area_id' => $best['id']])->save();

        return $best['id'];
    }

    /**
     * Rates in Pesanin's checkout shape. `code` is "courier:service" (e.g. "jne:reg");
     * `cod` tells whether the service accepts cash on delivery on this route.
     *
     * @return array<int, array{code:string, courier:string, name:string, service:string, cost:int, etd:string, cod:bool, provider:string}>
     */
    public function rates(Region $from, Region $to, int $weightGrams, int $value): array
    {
        $origin = $this->areaId($from);
        $dest = $this->areaId($to);
        $key = "biteship:rates:{$this->integration->id}:{$origin}:{$dest}:{$weightGrams}:".(int) ceil($value / 100000);

        return Cache::remember($key, now()->addMinutes(15), function () use ($origin, $dest, $weightGrams, $value) {
            $res = $this->call('post', '/v1/rates/couriers', [
                'origin_area_id' => $origin, 'destination_area_id' => $dest, 'couriers' => self::COURIERS,
                'items' => [['name' => 'Paket', 'value' => max(1, $value), 'weight' => max(1, $weightGrams), 'quantity' => 1]],
            ]);

            return collect($res['pricing'] ?? [])->map(fn ($p) => [
                'code' => $p['courier_code'].':'.$p['courier_service_code'],
                'courier' => $p['courier_code'],
                'name' => $p['courier_name'] ?? strtoupper($p['courier_code']),
                'service' => strtoupper($p['courier_service_code']),
                'cost' => (int) $p['price'],
                'etd' => trim(($p['shipment_duration_range'] ?? $p['duration'] ?? '').' '.(($p['shipment_duration_unit'] ?? 'days') === 'hours' ? 'jam' : 'hari')),
                'cod' => (bool) ($p['available_for_cash_on_delivery'] ?? false),
                'provider' => 'biteship',
            ])->filter(fn ($r) => $r['cost'] > 0)->sortBy('cost')->values()->all();
        });
    }

    /** Book the shipment for a Pesanin order; returns Biteship's order (id, courier.waybill_id …). */
    public function createOrder(Order $order): array
    {
        $order->loadMissing('user', 'product');
        $pickup = $order->user->pickupAddress();
        if (! $pickup?->region) {
            throw new BiteshipException('Alamat pickup toko belum diatur.');
        }
        $dest = $order->region_id ? Region::find($order->region_id) : null;
        if (! $dest) {
            throw new BiteshipException('Alamat tujuan pesanan belum lengkap.');
        }
        [$courier, $service] = array_pad(explode(':', (string) $order->courier_code, 2), 2, null);
        if (! $courier || ! $service) {
            throw new BiteshipException('Pesanan ini belum memakai ekspedisi dari Biteship.');
        }
        $cod = $order->payment_method === 'cod' && $order->payment_status !== 'paid';

        return $this->call('post', '/v1/orders', array_filter([
            'reference_id' => $order->code,
            'shipper_contact_name' => $order->user->store_name,
            'shipper_contact_phone' => $pickup->phone ?: $order->user->whatsapp,
            'origin_contact_name' => $pickup->name ?: $order->user->store_name,
            'origin_contact_phone' => $pickup->phone ?: $order->user->whatsapp,
            'origin_address' => $pickup->address,
            'origin_postal_code' => $pickup->postal_code ? (int) $pickup->postal_code : null,
            'origin_area_id' => $this->areaId($pickup->region),
            'destination_contact_name' => $order->customer_name,
            'destination_contact_phone' => $order->phone,
            'destination_contact_email' => $order->email,
            'destination_address' => trim($order->address.($order->village ? ", {$order->village}" : '')),
            'destination_postal_code' => $order->postal_code ? (int) $order->postal_code : null,
            'destination_area_id' => $this->areaId($dest),
            'destination_cash_on_delivery' => $cod ? (int) $order->total : null,
            'destination_cash_on_delivery_type' => $cod ? ($this->integration->settings['cod_type'] ?? '7_days') : null,
            'courier_company' => $courier,
            'courier_type' => $service,
            'delivery_type' => 'now',
            'order_note' => $order->notes,
            'items' => [[
                'name' => $order->product?->sku ?: ($order->product?->name ?? 'Paket'),
                'value' => (int) $order->subtotal,
                'quantity' => (int) $order->quantity,
                'weight' => max(1, (int) round(($order->weight ?: 1000) / max(1, $order->quantity))),
            ]],
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function order(string $id): array
    {
        return $this->call('get', "/v1/orders/{$id}");
    }

    /** Checkpoints for any waybill of a supported courier. */
    public function tracking(string $waybill, string $courier): array
    {
        return $this->call('get', "/v1/trackings/{$waybill}/couriers/{$courier}");
    }

    /** Apply a Biteship order (from the API or webhook) to the Pesanin order. */
    public static function apply(Order $order, array $b): void
    {
        $status = $b['status'] ?? null;
        $waybill = $b['courier']['waybill_id'] ?? $b['courier_waybill_id'] ?? null;
        $mapped = self::STATUS[$status] ?? null;
        $order->forceFill(array_filter([
            'shipping_provider' => 'biteship',
            'shipping_ref' => $b['id'] ?? $b['order_id'] ?? $order->shipping_ref,
            'tracking_number' => $waybill ?: $order->tracking_number,
            'shipping_status' => $mapped,
            'shipped_at' => in_array($mapped, ['picked_up', 'in_transit'], true) && ! $order->shipped_at ? now() : null,
            'status' => $mapped === 'delivered' && $order->status !== 'cancelled' ? 'completed' : ($mapped && $order->status === 'pending' ? 'processing' : null),
            'completed_at' => $mapped === 'delivered' && ! $order->completed_at ? now() : null,
        ], fn ($v) => $v !== null))->save();
    }

    private function call(string $method, string $path, array $data = []): array
    {
        /** @var Response $res */
        $res = Http::withHeaders(['Authorization' => $this->integration->key('api_key')])
            ->acceptJson()->timeout(20)->{$method}(self::HOST.$path, $data);
        $json = $res->json() ?? [];
        if ($res->failed() || ($json['success'] ?? true) === false) {
            throw new BiteshipException($json['error'] ?? $json['message'] ?? "Biteship error {$res->status()}");
        }

        return $json;
    }
}
