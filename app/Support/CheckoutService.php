<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\Region;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prices an order the way the checkout page shows it:
 * (unit price x qty) + bump products + shipping (from seller pickup address) + COD fee.
 */
class CheckoutService
{
    public static function paymentMethods(Product $product): array
    {
        $methods = $product->payment_methods ?: ['bank_transfer', 'cod'];

        // COD only makes sense for physical goods.
        return $product->type === 'digital' ? array_values(array_diff($methods, ['cod'])) ?: ['bank_transfer'] : $methods;
    }

    /** @return array{unit_price:int, subtotal:int, bump_price:int, bump_items:array, weight:int, rates:array, shipping:?array, shipping_cost:int, cod_fee:int, total:int, origin:?string} */
    public static function quote(Product $product, array $input): array
    {
        $qty = max(1, (int) ($input['quantity'] ?? $product->min_purchase));
        $variant = $input['variant'] ?? null;
        $unit = $product->unitPrice($variant, $qty);

        $chosen = collect($input['bump_items'] ?? [])->map(fn ($id) => (int) $id);
        $bumps = collect(ProductForm::bumpItems($product))->whereIn('product_id', $chosen->all())->values();
        $bump = (int) $bumps->sum('price');

        $subtotal = $unit * $qty + $bump;
        $weight = $product->weight * $qty + (int) $bumps->sum('weight');

        $rates = [];
        $shipping = null;
        $origin = null;
        if ($product->type === 'physical') {
            $pickup = $product->user->pickupAddress();
            $origin = $pickup?->region;
            $dest = ! empty($input['region_id']) ? Region::find($input['region_id']) : null;
            if ($origin && $dest && ! in_array($dest->province, ProductForm::excludedProvinces($product), true)) {
                $rates = self::adjustRates($product, self::liveRates($product, $origin, $dest, $weight, $subtotal, $input['payment_method'] ?? null));
                $shipping = collect($rates)->firstWhere('code', $input['courier'] ?? null)
                    ?? collect($rates)->firstWhere('cheapest', true);
            }
        }
        $shippingCost = (int) ($shipping['cost'] ?? 0);

        $coupon = CouponService::apply($product, $input['coupon_code'] ?? null, $subtotal, $qty, $shippingCost, $input['payment_method'] ?? null, $input['phone'] ?? null);
        $discount = $coupon['discount'] + $coupon['shipping_discount'];

        $codFee = ($input['payment_method'] ?? null) === 'cod' ? self::codFee($product, $subtotal - $coupon['discount'], $shippingCost - $coupon['shipping_discount']) : 0;

        return [
            'coupon' => $coupon['coupon']?->only(['code', 'kind', 'method']),
            'coupon_error' => $coupon['error'],
            'discount' => $discount,
            'unit_price' => $unit,
            'bump_price' => $bump,
            'bump_items' => $bumps->map(fn ($b) => ['product_id' => $b['product_id'], 'name' => $b['name'], 'price' => $b['price']])->all(),
            'subtotal' => $subtotal,
            'weight' => $weight,
            'origin' => $origin?->city,
            'rates' => $rates,
            'shipping' => $shipping,
            'shipping_cost' => $shippingCost,
            'cod_fee' => $codFee,
            'total' => max(0, $subtotal + $shippingCost - $discount + $codFee),
        ];
    }

    /**
     * Real courier rates from the seller's Biteship account when connected (COD-capable services only
     * when the buyer picks COD); otherwise Pesanin's local estimates.
     */
    private static function liveRates(Product $product, Region $origin, Region $dest, int $weight, int $value, ?string $method): array
    {
        // 1) RajaOngkir (Komerce) when connected: real rates for 10 couriers.
        if ($ro = \App\Support\RajaOngkir\RajaOngkir::for($product->user->integration('rajaongkir'))) {
            try {
                if ($rates = $ro->rates($origin, $dest, $weight)) {
                    return $rates;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("RajaOngkir rates for user {$product->user_id}: {$e->getMessage()}");
            }
        }
        // 2) Biteship (also knows which services accept COD).
        $biteship = \App\Support\Biteship\Biteship::for($product->user->integration('biteship'));
        if ($biteship) {
            try {
                $rates = $biteship->rates($origin, $dest, $weight, $value);
                if ($method === 'cod') {
                    $rates = array_values(array_filter($rates, fn ($r) => $r['cod']));
                }
                if ($rates) {
                    return $rates;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Biteship rates for user {$product->user_id}: {$e->getMessage()}");
            }
        }

        return ShippingRates::rates($origin, $dest, $weight);
    }

    /** Step-2 "Pengiriman" settings: allowed couriers, extra fee and shipping discount. */
    public static function adjustRates(Product $product, array $rates): array
    {
        $cfg = $product->form_settings['shipping'] ?? [];
        if (! empty($cfg['couriers'])) {
            $rates = array_values(array_filter($rates, fn ($r) => in_array($r['courier'] ?? $r['code'], $cfg['couriers'], true)));
        }
        $extra = (int) ($cfg['extra_fee'] ?? 0);
        $disc = $cfg['discount'] ?? [];
        $rates = array_map(function ($r) use ($extra, $disc) {
            $cost = max(0, $r['cost'] + $extra);
            if (! empty($disc['enabled'])) {
                $cost = ($disc['type'] ?? 'amount') === 'percent'
                    ? (int) round($cost * (1 - min(100, (float) $disc['value']) / 100))
                    : max(0, $cost - (int) $disc['value']);
            }

            return ['original_cost' => $r['cost']] + array_merge($r, ['cost' => $cost]);
        }, $rates);
        if (! $rates) {
            return [];
        }
        $min = min(array_column($rates, 'cost'));

        return array_map(fn ($r) => array_merge($r, ['cheapest' => $r['cost'] === $min]), $rates);
    }

    /** COD fee: per-product "COD Settings" falling back to the store default percentage. */
    private static function codFee(Product $product, int $subtotal, int $shipping): int
    {
        $cfg = $product->form_settings['payment']['cod'] ?? [];
        $pct = (float) ($cfg['fee_percent'] ?? $product->user->cod_fee_percent);
        $base = ($cfg['fee_type'] ?? 'product_shipping') === 'product' ? $subtotal : $subtotal + $shipping;
        $fee = (int) round($base * $pct / 100);
        if (! empty($cfg['min_fee'])) {
            $fee = max($fee, (int) $cfg['min_fee']);
        }
        if (! empty($cfg['max_fee'])) {
            $fee = min($fee, (int) $cfg['max_fee']);
        }

        return $fee;
    }

    public static function rules(Product $product): array
    {
        $f = ProductForm::fields($product);
        $custom = [];
        foreach (ProductForm::customFields($product) as $cf) {
            $custom['custom_fields.'.$cf['id']] = [$cf['required'] ? 'required' : 'nullable', 'string', 'max:300'];
        }
        $village = ! empty($product->form_settings['fields']['village']) && $product->type === 'physical';
        $req = fn (string $k) => $f[$k]['enabled'] && $f[$k]['required'] ? 'required' : 'nullable';
        $variantRequired = ! empty($product->variants) && ($product->form_settings['variant_required'] ?? true);

        return [
            'checkout_token' => 'nullable|string|size:32',
            'website' => 'prohibited', // honeypot against bots
            'customer_name' => 'required|string|max:120',
            'phone' => array_values(array_filter(['required', 'string', 'max:30',
                $product->whatsapp_validator ? 'regex:/^(\+?62|0)8[0-9]{7,12}$/' : 'regex:/^[0-9+\s-]{8,20}$/'])),
            'email' => [$req('email'), 'email'],
            'address' => [$req('address'), 'string', 'max:500'],
            'region_id' => [$req('region'), 'exists:regions,id'],
            'village' => [$village ? 'required' : 'nullable', 'string', 'max:100'],
            'custom_fields' => 'nullable|array',
            'courier' => 'nullable|string|max:40',
            'payment_channel' => 'nullable|string|max:30',
            'variant' => [$variantRequired ? 'required' : 'nullable', 'string'],
            'quantity' => "required|integer|min:{$product->min_purchase}|max:1000",
            'bump_items' => 'nullable|array|max:3',
            'bump_items.*' => 'integer',
            'payment_method' => 'required|in:'.implode(',', self::paymentMethods($product)),
            'notes' => [$req('notes'), 'string', 'max:500'],
            'coupon_code' => 'nullable|string|max:40',
            'ref' => 'nullable|string|max:40',
            'utm_source' => 'nullable|string|max:100',
            'utm_medium' => 'nullable|string|max:100',
            'utm_campaign' => 'nullable|string|max:100',
            'utm_term' => 'nullable|string|max:100',
            'utm_content' => 'nullable|string|max:100',
        ] + $custom;
    }

    public static function messages(): array
    {
        return [
            'phone.regex' => 'Nomor WhatsApp tidak valid (contoh: 081234567890).',
            'region_id.required' => 'Pilih Kota / Kecamatan tujuan.',
            'variant.required' => 'Pilih varian terlebih dahulu.',
            'website.prohibited' => 'Pesanan tidak dapat diproses.',
        ];
    }

    public static function place(Product $product, array $data, array $extra = []): Order
    {
        unset($data['website']);

        return DB::transaction(function () use ($product, $data, $extra) {
            $product = Product::with('user')->lockForUpdate()->find($product->id);
            $qty = (int) $data['quantity'];
            $variant = $data['variant'] ?? null;

            $stock = $product->availableStock($variant);
            if ($stock !== null && $stock < $qty && ! $product->allow_oversell) {
                throw ValidationException::withMessages(['quantity' => "Stok tidak cukup (tersisa {$stock})."]);
            }

            $region = ! empty($data['region_id']) ? Region::find($data['region_id']) : null;
            if ($region && in_array($region->province, ProductForm::excludedProvinces($product), true)) {
                throw ValidationException::withMessages(['region_id' => "Maaf, pengiriman ke {$region->province} tidak tersedia."]);
            }

            $quote = self::quote($product, $data);
            if (! empty($data['coupon_code']) && $quote['coupon_error']) {
                throw ValidationException::withMessages(['coupon_code' => $quote['coupon_error']]);
            }
            $affiliate = ! empty($data['ref']) ? \App\Models\Affiliate::where(['user_id' => $product->user_id, 'code' => $data['ref'], 'is_active' => true])->first() : null;
            if ($product->type === 'physical' && ! $quote['shipping']) {
                throw ValidationException::withMessages(['region_id' => 'Pengiriman ke lokasi ini belum tersedia. Penjual belum mengatur alamat pickup.']);
            }

            $order = ! empty($data['checkout_token'])
                ? Order::where(['checkout_token' => $data['checkout_token'], 'product_id' => $product->id, 'is_abandoned' => true])->first()
                : null;
            $order ??= new Order(['checkout_token' => $data['checkout_token'] ?? null]);

            $labels = collect(ProductForm::customFields($product))->pluck('label', 'id');
            $data['custom_fields'] = collect($data['custom_fields'] ?? [])->filter(fn ($v, $k) => $labels->has($k) && filled($v))
                ->mapWithKeys(fn ($v, $k) => [$labels[$k] => $v])->all() ?: null;

            $order->forceFill(collect($data)->except(['checkout_token', 'courier', 'bump_items', 'coupon_code', 'ref'])->all() + [
                'coupon_code' => $quote['coupon']['code'] ?? null,
                'discount' => $quote['discount'],
                'affiliate_id' => $affiliate?->id,
                'user_id' => $product->user_id,
                'product_id' => $product->id,
                'province' => $region?->province,
                'postal_code' => $data['postal_code'] ?? $region?->postal_code,
                'village' => $data['village'] ?? $region?->subdistrict,
                'city' => $region?->city,
                'district' => $region?->district,
                'is_abandoned' => false,
                'unit_price' => $quote['unit_price'],
                'bump_selected' => $quote['bump_price'] > 0,
                'bump_price' => $quote['bump_price'],
                'bump_items' => $quote['bump_items'] ?: null,
                'subtotal' => $quote['subtotal'],
                'weight' => $quote['weight'],
                'courier' => $quote['shipping']['name'] ?? null,
                'courier_service' => $quote['shipping']['service'] ?? null,
                'courier_code' => $quote['shipping']['code'] ?? null,
                'shipping_provider' => $quote['shipping']['provider'] ?? ($quote['shipping'] ? 'manual' : null),
                'shipping_etd' => $quote['shipping']['etd'] ?? null,
                'shipping_cost' => $quote['shipping_cost'],
                'cod_fee' => $quote['cod_fee'],
                'total' => $quote['total'],
                'pickup_address_id' => $product->type === 'physical' ? $product->user->pickupAddress()?->id : null,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'created_at' => now(),
            ] + $extra)->save();

            if ($product->track_stock) {
                self::decrementStock($product, $variant, $qty);
            }
            if ($quote['coupon']) {
                \App\Models\Coupon::where('user_id', $product->user_id)->where('code', $quote['coupon']['code'])->where('method', $quote['coupon']['method'])->increment('used_count');
            }

            return $order->load('product', 'user');
        });
    }

    private static function decrementStock(Product $product, ?string $variant, int $qty): void
    {
        $variants = $product->variants ?? [];
        foreach ($variants as &$v) {
            if (($v['name'] ?? null) === $variant && isset($v['stock'])) {
                $v['stock'] = (int) $v['stock'] - $qty;
                $product->variants = $variants;
                $product->save();

                return;
            }
        }
        $product->decrement('stock', $qty);
    }
}
