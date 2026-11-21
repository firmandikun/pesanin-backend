<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceShop;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeProductTools;
use Illuminate\Http\Request;

/** Marketplace → Semua Produk: Naikkan Produk otomatis, statistik produk, ubah harga / judul / arsip massal. */
class ShopeeProductToolsController extends Controller
{
    private function shop(Request $request, int $id): MarketplaceShop
    {
        $shop = MarketplaceShop::where('user_id', $request->user()->id)->findOrFail($id);
        abort_if($shop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');

        return $shop;
    }

    private function shopee(callable $fn)
    {
        try {
            return $fn();
        } catch (ShopeeException $e) {
            abort(422, 'Shopee menolak: '.$e->getMessage());
        }
    }

    /** Boost setup of every shop. */
    public function boostIndex(Request $request)
    {
        return ['slots' => ShopeeProductTools::BOOST_SLOTS, 'shops' => MarketplaceShop::where('user_id', $request->user()->id)->where('is_demo', false)
            ->where('status', '!=', 'disconnected')->get(['id', 'shop_name', 'boost'])
            ->map(function ($s) {
                $cfg = ShopeeProductTools::boostConfig($s);
                $names = \App\Models\MarketplaceListing::where('marketplace_shop_id', $s->id)->whereIn('item_id', $cfg['items'] ?: [0])
                    ->get(['item_id', 'name', 'image', 'status'])->unique('item_id')->keyBy('item_id');

                return ['id' => $s->id, 'shop_name' => $s->shop_name] + $cfg + ['products' => collect($cfg['items'])->map(fn ($i) => [
                    'item_id' => (int) $i, 'name' => $names[$i]->name ?? "Produk {$i}", 'image' => $names[$i]->image ?? null, 'status' => $names[$i]->status ?? null,
                ])->values()];
            })->values()];
    }

    public function boostUpdate(Request $request, int $shop)
    {
        $s = $this->shop($request, $shop);
        $d = $request->validate(['enabled' => 'sometimes|boolean', 'items' => 'sometimes|array|max:500', 'items.*' => 'integer']);
        $cfg = ShopeeProductTools::boostConfig($s);
        if (array_key_exists('items', $d)) {
            $cfg['items'] = array_values(array_unique(array_map('intval', $d['items'])));
            $cfg['cursor'] = 0;
        }
        if (array_key_exists('enabled', $d)) {
            $cfg['enabled'] = $d['enabled'];
        }
        $s->update(['boost' => $cfg]);
        // Turning it on boosts right away instead of waiting for the next run.
        if ($cfg['enabled'] && $cfg['items']) {
            $cfg = (new ShopeeProductTools)->boost($s->fresh());
        }

        return ['id' => $s->id, 'shop_name' => $s->shop_name] + $cfg;
    }

    public function boostRun(Request $request, int $shop)
    {
        $s = $this->shop($request, $shop);

        return ['id' => $s->id, 'shop_name' => $s->shop_name] + (new ShopeeProductTools)->boost($s, true);
    }

    /** Refresh views / likes / sales of every product now (also runs daily). */
    public function statsSync(Request $request)
    {
        $n = 0;
        $errors = [];
        foreach (MarketplaceShop::where('user_id', $request->user()->id)->where('is_demo', false)->where('status', 'active')->get() as $s) {
            try {
                $n += (new ShopeeProductTools)->syncStats($s);
            } catch (\Throwable $e) {
                $errors[] = "{$s->shop_name}: {$e->getMessage()}";
            }
        }

        return ['products' => $n, 'errors' => $errors];
    }

    public function prices(Request $request)
    {
        $d = $request->validate(['prices' => 'required|array|min:1|max:500', 'prices.*.id' => 'required|integer', 'prices.*.price' => 'required|integer|min:100|max:1000000000']);

        return (new ShopeeProductTools)->updatePrices($request->user()->id, collect($d['prices'])->mapWithKeys(fn ($p) => [(int) $p['id'] => (int) $p['price']])->all());
    }

    public function unlist(Request $request)
    {
        $d = $request->validate(['items' => 'required|array|min:1|max:500', 'items.*.shop_id' => 'required|integer', 'items.*.item_id' => 'required|integer', 'unlist' => 'required|boolean']);

        return (new ShopeeProductTools)->setUnlisted($request->user()->id, collect($d['items'])->map(fn ($i) => [(int) $i['shop_id'], (int) $i['item_id']])->all(), $d['unlist']);
    }

    /** Buat SKU: suggestions for products without SKU (or the selected ones), editable before saving. */
    public function skuPreview(Request $request)
    {
        $d = $request->validate(['mode' => 'required|in:abbr,number', 'prefix' => 'nullable|string|max:12', 'start' => 'nullable|integer|min:1',
            'scope' => 'required|in:empty,selected', 'keys' => 'array|max:500', 'keys.*' => 'string', 'shop_id' => 'nullable|integer']);
        $q = \App\Models\MarketplaceListing::where('user_id', $request->user()->id)->with('shop:id,shop_name,is_demo')
            ->whereHas('shop', fn ($s) => $s->where('is_demo', false)->where('status', '!=', 'disconnected'))
            ->when($d['shop_id'] ?? null, fn ($w, $id) => $w->where('marketplace_shop_id', $id));
        if ($d['scope'] === 'selected') {
            $keys = collect($d['keys'] ?? [])->map(fn ($k) => array_map('intval', explode(':', $k)));
            abort_if($keys->isEmpty(), 422, 'Pilih produk dulu.');
            $q->where(fn ($w) => $keys->each(fn ($k) => $w->orWhere(fn ($x) => $x->where('marketplace_shop_id', $k[0])->where('item_id', $k[1] ?? 0))));
        } else {
            $q->where(fn ($w) => $w->whereNull('sku')->orWhere('sku', ''));
        }
        $listings = $q->limit(500)->get();

        return ['rows' => \App\Support\Shopee\SkuGenerator::suggest($request->user()->id, $listings, $d['mode'], (string) ($d['prefix'] ?? ''), (int) ($d['start'] ?? 1))];
    }

    public function skuSave(Request $request)
    {
        $d = $request->validate(['rows' => 'required|array|min:1|max:500', 'rows.*.id' => 'required|integer', 'rows.*.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._\/-]+$/']],
            ['rows.*.sku.regex' => 'SKU hanya boleh huruf, angka, titik, garis miring, strip dan garis bawah (tanpa spasi).']);
        $stocks = $request->validate(['stocks' => 'nullable|array|max:500', 'stocks.*' => 'integer|min:0|max:1000000'])['stocks'] ?? [];
        $skus = collect($d['rows'])->mapWithKeys(fn ($r) => [(int) $r['id'] => strtoupper(trim($r['sku']))])->all();
        $res = (new \App\Support\Shopee\SkuGenerator)->save($request->user()->id, $skus);
        // Shared stock for SKUs used by several listings (only those whose SKU was saved).
        $ok = collect($skus)->except(array_keys($res['failed']))->values()->map(fn ($x) => strtoupper($x))->unique()->all();
        $stocks = collect($stocks)->mapWithKeys(fn ($v, $k) => [strtoupper((string) $k) => (int) $v])->only($ok)->all();
        $res['masters'] = 0;
        if ($stocks) {
            if (! \App\Support\Plans::hasFeature($request->user(), 'stock_master')) {
                $res['stock_note'] = 'Stok bersama butuh fitur Stok Master (upgrade paket). SKU tetap tersimpan.';
            } else {
                $res['masters'] = \App\Support\Shopee\SkuGenerator::shareStock($request->user(), $stocks);
            }
        }

        return $res;
    }

    public function itemText(Request $request, int $shop, int $item)
    {
        $s = $this->shop($request, $shop);

        return $this->shopee(fn () => (new ShopeeProductTools)->itemText($s, $item));
    }

    public function updateText(Request $request, int $shop, int $item)
    {
        $s = $this->shop($request, $shop);
        $d = $request->validate(['name' => 'required|string|min:10|max:255', 'description' => 'nullable|string|min:20|max:5000']);
        $this->shopee(fn () => (new ShopeeProductTools)->updateText($s, $item, trim($d['name']), isset($d['description']) ? trim($d['description']) : null));

        return ['ok' => true, 'name' => trim($d['name'])];
    }
}
