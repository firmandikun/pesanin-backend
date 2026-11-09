<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Product;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Upload produk ke Shopee, one shop per request so the UI can show progress across many shops.
 * Flow: categories → category detail (attributes, brands, limits) → upload images once (image_ids work
 * in every shop) → publish to each selected shop (add_item + init_tier_variation) → listing rows linked
 * to the Pesanin product so stock/HPP/profit work right away.
 */
class ShopeePublishController extends Controller
{
    /** Leaf categories with their full path, for a searchable picker. */
    public function categories(Request $request)
    {
        $all = collect((new ShopeeClient)->categories($this->anyShop($request)))->keyBy('id');
        $path = function ($c) use ($all) {
            $names = [];
            for ($i = 0; $c && $i < 8; $i++, $c = $all[$c['parent']] ?? null) {
                array_unshift($names, $c['name']);
            }

            return implode(' › ', $names);
        };

        return $all->where('leaf', true)->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'path' => $path($c)])->sortBy('path')->values();
    }

    public function category(Request $request, int $category)
    {
        $api = new ShopeeClient;
        $shop = $this->anyShop($request);
        try {
            $limit = $api->itemLimit($shop, $category);
        } catch (Throwable) {
            $limit = [];
        }

        return [
            'attributes' => $api->attributes($shop, $category),
            'brands' => $api->brands($shop, $category),
            'limits' => [
                'images_min' => (int) ($limit['item_image_count_limit']['min_limit'] ?? 1),
                'images_max' => (int) ($limit['item_image_count_limit']['max_limit'] ?? 9),
                'name_min' => (int) ($limit['item_name_length_limit']['min_limit'] ?? 1),
                'name_max' => (int) ($limit['item_name_length_limit']['max_limit'] ?? 120),
                'description_max' => (int) ($limit['item_description_length_limit']['max_limit'] ?? 3000),
                'price_min' => (int) ($limit['price_limit']['min_limit'] ?? 100),
            ],
        ];
    }

    /** Shops the seller can publish to, with the couriers each will use. */
    public function shops(Request $request)
    {
        $api = new ShopeeClient;

        return $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)->get()->map(function ($s) use ($api) {
            try {
                $channels = $api->enabledChannels($s);
                $error = $channels ? null : 'Belum ada jasa kirim aktif di toko ini.';
            } catch (Throwable $e) {
                [$channels, $error] = [[], $e->getMessage()];
            }

            return ['id' => $s->id, 'shop_id' => $s->shop_id, 'name' => $s->shop_name, 'channels' => $channels, 'error' => $error];
        });
    }

    /** Images: uploaded files and/or existing product image URLs → Shopee image_ids. */
    public function images(Request $request)
    {
        $request->validate(['files' => 'nullable|array|max:9', 'files.*' => 'image|mimes:jpg,jpeg,png|max:10240', 'urls' => 'nullable|array|max:9', 'urls.*' => 'url']);
        $api = new ShopeeClient;
        $out = [];
        try {
            foreach ($request->file('files', []) as $f) {
                $out[] = ['id' => $api->uploadImage($f->get(), $f->getClientOriginalName()), 'name' => $f->getClientOriginalName()];
            }
            foreach ($request->input('urls', []) as $url) {
                $res = Http::timeout(20)->get($url);
                abort_unless($res->successful(), 422, "Gambar tidak bisa diambil: {$url}");
                $out[] = ['id' => $api->uploadImage($res->body(), basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg'), 'name' => basename($url)];
            }
        } catch (ShopeeException $e) {
            abort(422, 'Upload gambar ke Shopee gagal: '.$e->getMessage());
        }

        return collect($out)->map(fn ($i) => $i + ['url' => $this->imageUrl($i['id'])]);
    }

    /** Publish to ONE shop; the UI loops over the selected shops. */
    public function publish(Request $request, MarketplaceShop $shop)
    {
        abort_unless($shop->user_id === $request->user()->id && $shop->status === 'active', 404);
        $d = $request->validate([
            'product_id' => 'nullable|integer',
            'name' => 'required|string|min:2|max:226',
            'description' => 'required|string|min:1|max:3000',
            'category_id' => 'required|integer',
            'brand_id' => 'nullable|integer',
            'brand_name' => 'nullable|string|max:100',
            'price' => 'required|integer|min:100',
            'stock' => 'required|integer|min:0|max:999999',
            'sku' => 'nullable|string|max:100',
            'weight' => 'required|integer|min:1|max:1000000', // grams
            'length' => 'nullable|integer|min:1|max:1000', 'width' => 'nullable|integer|min:1|max:1000', 'height' => 'nullable|integer|min:1|max:1000',
            'image_ids' => 'required|array|min:1|max:9', 'image_ids.*' => 'string',
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required|integer',
            'attributes.*.value_ids' => 'nullable|array', 'attributes.*.value_ids.*' => 'integer',
            'attributes.*.text' => 'nullable|string|max:200',
            'attributes.*.unit' => 'nullable|string|max:20',
            'tiers' => 'nullable|array|max:2',
            'tiers.*.name' => 'required|string|max:14',
            'tiers.*.options' => 'required|array|min:1|max:50', 'tiers.*.options.*' => 'string|max:20',
            'models' => 'nullable|array|max:50',
            'models.*.index' => 'required|array', 'models.*.index.*' => 'integer',
            'models.*.price' => 'required|integer|min:100',
            'models.*.stock' => 'required|integer|min:0',
            'models.*.sku' => 'nullable|string|max:100',
        ]);
        $product = ! empty($d['product_id']) ? Product::where('user_id', $request->user()->id)->find($d['product_id']) : null;
        $api = new ShopeeClient;

        try {
            $channels = $api->enabledChannels($shop);
            abort_if(! $channels, 422, 'Belum ada jasa kirim aktif di toko ini. Aktifkan di Seller Centre → Pengaturan Pengiriman.');
            $models = $d['models'] ?? [];
            $item = array_filter([
                'item_name' => $d['name'], 'description' => $d['description'], 'category_id' => $d['category_id'],
                'original_price' => $models ? min(array_column($models, 'price')) : $d['price'],
                'seller_stock' => [['stock' => $models ? array_sum(array_column($models, 'stock')) : $d['stock']]],
                'item_sku' => $d['sku'] ?? null,
                'weight' => round($d['weight'] / 1000, 3),
                'dimension' => ! empty($d['length']) && ! empty($d['width']) && ! empty($d['height'])
                    ? ['package_length' => $d['length'], 'package_width' => $d['width'], 'package_height' => $d['height']] : null,
                'item_status' => 'NORMAL', 'condition' => 'NEW',
                'image' => ['image_id_list' => array_values($d['image_ids'])],
                'brand' => ['brand_id' => (int) ($d['brand_id'] ?? 0), 'original_brand_name' => $d['brand_name'] ?? 'NoBrand'],
                'logistic_info' => collect($channels)->map(fn ($c) => ['logistic_id' => $c['id'], 'enabled' => true])->all(),
                'attribute_list' => $this->attributeList($d['attributes'] ?? []) ?: null,
            ], fn ($v) => $v !== null);

            $res = $api->addItem($shop, $item);
            $itemId = (int) ($res['item_id'] ?? 0);
            abort_unless($itemId, 422, 'Shopee tidak mengembalikan item_id.');

            $listings = [];
            $imageUrl = $this->imageUrl($d['image_ids'][0]);
            if ($models && ! empty($d['tiers'])) {
                $tierRes = $api->initTierVariation($shop, $itemId,
                    collect($d['tiers'])->map(fn ($t) => ['name' => $t['name'], 'option_list' => collect($t['options'])->map(fn ($o) => ['option' => $o])->all()])->all(),
                    collect($models)->map(fn ($m) => array_filter(['tier_index' => $m['index'], 'original_price' => $m['price'], 'seller_stock' => [['stock' => $m['stock']]], 'model_sku' => $m['sku'] ?? null]))->all());
                foreach ($tierRes['model'] ?? [] as $i => $m) {
                    $src = collect($models)->first(fn ($x) => $x['index'] === ($m['tier_index'] ?? null)) ?? $models[$i] ?? [];
                    $listings[] = $this->listing($shop, $product, $itemId, (int) $m['model_id'], $d['name'],
                        collect($m['tier_index'] ?? [])->map(fn ($ti, $k) => $d['tiers'][$k]['options'][$ti] ?? '')->implode(', '), $src['sku'] ?? null, $src['price'] ?? $d['price'], $src['stock'] ?? 0, $imageUrl);
                }
            } else {
                $listings[] = $this->listing($shop, $product, $itemId, 0, $d['name'], null, $d['sku'] ?? null, $d['price'], $d['stock'], $imageUrl);
            }
        } catch (ShopeeException $e) {
            abort(422, $e->getMessage());
        }
        ActivityLog::record($request->user(), 'marketplace.publish', "Upload \"{$d['name']}\" ke {$shop->shop_name}");

        return ['item_id' => $itemId, 'listings' => count($listings), 'shop' => $shop->shop_name];
    }

    /** UI attribute answers → Shopee attribute_list (dropdown ids, or value_id 0 + text/timestamp). */
    private function attributeList(array $answers): array
    {
        return collect($answers)->map(function ($a) {
            $values = collect($a['value_ids'] ?? [])->filter()->map(fn ($id) => ['value_id' => (int) $id]);
            if ($values->isEmpty() && isset($a['text']) && trim($a['text']) !== '') {
                $values = collect([array_filter(['value_id' => 0, 'original_value_name' => trim($a['text']), 'value_unit' => $a['unit'] ?? null], fn ($v) => $v !== null)]);
            }

            return $values->isEmpty() ? null : ['attribute_id' => (int) $a['id'], 'attribute_value_list' => $values->values()->all()];
        })->filter()->values()->all();
    }

    private function listing(MarketplaceShop $shop, ?Product $product, int $itemId, int $modelId, string $name, ?string $modelName, ?string $sku, int $price, int $stock, string $image): MarketplaceListing
    {
        $l = MarketplaceListing::firstOrNew(['marketplace_shop_id' => $shop->id, 'item_id' => $itemId, 'model_id' => $modelId]);
        $l->user_id = $shop->user_id;
        $l->fill(['product_id' => $product?->id, 'name' => $name, 'model_name' => $modelName, 'sku' => $sku, 'price' => $price, 'stock' => $stock, 'status' => 'NORMAL', 'image' => $image,
            'cost_price' => $l->cost_price ?? $product?->cost_price])->save();

        return $l;
    }

    private function imageUrl(string $id): string
    {
        return "https://cf.shopee.co.id/file/{$id}";
    }

    private function anyShop(Request $request): MarketplaceShop
    {
        $shop = $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)
            ->when($request->query('shop'), fn ($q, $id) => $q->whereKey($id))->first();
        abort_unless($shop, 422, 'Hubungkan toko Shopee dulu.');

        return $shop;
    }
}
