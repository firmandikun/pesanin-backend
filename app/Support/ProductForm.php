<?php

namespace App\Support;

use App\Models\Product;

/** Normalized "Halaman Checkout" (step 2) settings with defaults. */
class ProductForm
{
    public const FIELD_KEYS = ['name', 'phone', 'email', 'quantity', 'notes', 'address', 'region'];

    /** @return array<string, array{enabled: bool, required: bool, label: ?string}> */
    public static function fields(Product $product): array
    {
        $fs = $product->form_settings ?? [];
        $physical = $product->type === 'physical';
        $defaults = [
            'name' => [true, true],
            'phone' => [true, true],
            'email' => [! $physical, ! $physical],
            'quantity' => [true, false],
            'notes' => [false, false],
            'address' => [$physical, $physical],
            'region' => [$physical, $physical],
        ];
        // Legacy keys from the first version of the editor.
        if (isset($fs['email']) && is_string($fs['email'])) {
            $defaults['email'] = [$fs['email'] !== 'hidden', $fs['email'] === 'required'];
        }
        if (isset($fs['notes']) && is_bool($fs['notes'])) {
            $defaults['notes'] = [$fs['notes'], false];
        }

        $configured = collect($fs['fields']['items'] ?? [])->keyBy('key');
        $out = [];
        foreach ($defaults as $key => [$enabled, $required]) {
            $c = $configured->get($key, []);
            $out[$key] = [
                'enabled' => (bool) ($c['enabled'] ?? $enabled),
                'required' => (bool) ($c['required'] ?? $required),
                'label' => $c['label'] ?? null,
            ];
        }
        // Name & phone are always on; shipping needs address + region for physical goods.
        $out['name'] = ['enabled' => true, 'required' => true] + $out['name'];
        $out['phone'] = ['enabled' => true, 'required' => true] + $out['phone'];
        if ($physical) {
            $out['region']['enabled'] = $out['region']['required'] = true;
            $out['address']['enabled'] = true;
        } else {
            $out['address']['enabled'] = $out['region']['enabled'] = false;
            $out['email']['enabled'] = $out['email']['required'] = true;
        }

        return $out;
    }

    /** Extra seller-defined inputs ("Tambah Form Lain"). @return array<int, array{id: string, label: string, required: bool}> */
    public static function customFields(Product $product): array
    {
        return collect($product->form_settings['fields']['custom'] ?? [])
            ->filter(fn ($f) => ! empty($f['id']) && ! empty($f['label']))
            ->map(fn ($f) => ['id' => (string) $f['id'], 'label' => (string) $f['label'], 'required' => (bool) ($f['required'] ?? false)])
            ->values()->all();
    }

    public static function component(Product $product, string $key, bool $default = true): bool
    {
        $c = $product->form_settings['components'][$key] ?? null;

        return is_array($c) ? (bool) ($c['enabled'] ?? $default) : (is_bool($c) ? $c : $default);
    }

    public static function excludedProvinces(Product $product): array
    {
        $ex = $product->form_settings['exclude_locations'] ?? [];

        return ! empty($ex['enabled']) ? ($ex['provinces'] ?? []) : [];
    }

    /** Bump offers: up to 3 other active products of the same seller. */
    public static function bumpItems(Product $product): array
    {
        if (empty($product->bump['enabled'])) {
            return [];
        }
        $items = collect($product->bump['items'] ?? [])->take(3);
        $products = Product::whereIn('id', $items->pluck('product_id'))->where('user_id', $product->user_id)
            ->where('status', 'active')->get()->keyBy('id');

        return $items->map(function ($it) use ($products) {
            $p = $products->get($it['product_id'] ?? 0);

            return $p ? [
                'product_id' => $p->id,
                'name' => $p->name,
                'image' => $p->images[0] ?? null,
                'price' => (int) ($it['price'] ?? $p->sellingPrice()),
                'normal_price' => (int) $p->price,
                'description' => $it['description'] ?? null,
                'weight' => (int) $p->weight,
            ] : null;
        })->filter()->values()->all();
    }
}
