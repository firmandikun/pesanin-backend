<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Buat SKU: suggests SKUs for Shopee products/variations and saves them to Shopee (SKU induk + kode variasi).
 *  - abbr:   initials of the product name + short variation code, e.g. "Cover Knalpot Airblade" / "Hitam, Old" → CKA-HIT-OLD
 *  - number: prefix + running number, e.g. PSN-0001
 * The same product + variation sold in several shops gets the same SKU, so Stok Master can merge it right away.
 * Suggested SKUs never collide with SKUs the seller already uses.
 */
class SkuGenerator
{
    /** Words that carry no meaning in a product name. */
    private const STOP = ['dan', 'untuk', 'yang', 'dengan', 'di', 'ke', 'dari', 'atau', 'new', 'original', 'ori', 'murah', 'promo', 'termurah', 'terbaru', 'bisa', 'cod', 'free', 'gratis', 'the', 'and', 'for', 'with', 'of'];

    /** Same product: names equal once notes like "(Bisa COD 2)" / "[READY]" and spacing are ignored. */
    private static function key(string $name, ?string $variant): string
    {
        $clean = fn ($v) => Str::lower(trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)|\[[^\]]*\]/', ' ', (string) $v))));

        return $clean($name).'|'.$clean($variant);
    }

    public static function abbrName(string $name): string
    {
        $name = preg_replace('/\([^)]*\)|\[[^\]]*\]/', ' ', $name); // drop (Bisa COD) / [READY] notes
        $words = collect(preg_split('/[^A-Za-z0-9]+/', Str::ascii($name)))->filter()
            ->reject(fn ($w) => in_array(Str::lower($w), self::STOP, true))->values();
        $code = $words->take(4)->map(fn ($w) => ctype_digit($w) ? $w : Str::upper($w[0]))->implode('');
        if (strlen($code) < 3 && $words->isNotEmpty()) {
            $code = Str::upper(substr(preg_replace('/[^A-Za-z0-9]/', '', $words->first()), 0, 4)).substr($code, 1);
        }

        return substr($code ?: 'PRD', 0, 8);
    }

    /** Every word of the variation, 3 letters each, up to 4: "Airblade New + Baut" → AIR-NEW-BAU, "Hitam, XL" → HIT-XL. */
    public static function abbrVariant(?string $variant): string
    {
        if (! $variant) {
            return '';
        }

        return collect(preg_split('/[^A-Za-z0-9]+/', Str::ascii($variant)))->filter()
            ->take(4)->map(fn ($p) => Str::upper(ctype_digit($p) ? $p : substr($p, 0, 3)))->implode('-');
    }

    /**
     * @param  Collection<int, MarketplaceListing>  $listings
     * @return array<int, array> one row per listing with its suggested SKU
     */
    public static function suggest(int $userId, Collection $listings, string $mode, string $prefix = '', int $start = 1): array
    {
        $prefix = Str::upper(preg_replace('/[^A-Za-z0-9-]/', '', $prefix));
        // SKUs already used by the seller (except on the listings being renamed) must stay unique.
        $taken = MarketplaceListing::where('user_id', $userId)->whereNotIn('id', $listings->pluck('id')->all() ?: [0])
            ->whereNotNull('sku')->where('sku', '!=', '')->pluck('sku')->map(fn ($s) => Str::upper($s))->flip()->all();
        $byKey = [];
        $n = $start;
        $rows = [];
        foreach ($listings->sortBy(fn ($l) => [$l->name, $l->model_name])->values() as $l) {
            $k = self::key($l->name, $l->model_name);
            if (! isset($byKey[$k])) {
                if ($mode === 'number') {
                    do {
                        $sku = ($prefix ?: 'SKU').'-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
                    } while (isset($taken[$sku]));
                } else {
                    $base = trim(($prefix ? "{$prefix}-" : '').self::abbrName($l->name).($l->model_name ? '-'.self::abbrVariant($l->model_name) : ''), '-');
                    $sku = $base;
                    for ($i = 2; isset($taken[$sku]); $i++) {
                        $sku = "{$base}-{$i}";
                    }
                }
                $taken[$sku] = true;
                $byKey[$k] = $sku;
            }
            $rows[] = [
                'id' => $l->id, 'shop_id' => $l->marketplace_shop_id, 'shop_name' => $l->shop?->shop_name, 'item_id' => (int) $l->item_id, 'model_id' => (int) $l->model_id,
                'name' => $l->name, 'model_name' => $l->model_name, 'image' => $l->image, 'current' => $l->sku, 'sku' => mb_substr($byKey[$k], 0, 100), 'stock' => (int) $l->stock,
            ];
        }

        return $rows;
    }

    /**
     * Save SKUs to Shopee. Variation SKU = model_sku; a product without variations = item_sku. For products with
     * variations the SKU induk is set to the shared front part when it has none yet.
     * @param  array<int, string>  $skus  listing id => sku
     * @return array{updated: int, failed: array<int, string>}
     */
    public function save(int $userId, array $skus): array
    {
        $api = new ShopeeClient;
        $listings = MarketplaceListing::where('user_id', $userId)->whereIn('id', array_keys($skus))->with('shop')->get();
        $updated = 0;
        $failed = [];
        foreach ($listings->groupBy(fn ($l) => "{$l->marketplace_shop_id}:{$l->item_id}") as $group) {
            /** @var MarketplaceShop $shop */
            $shop = $group->first()->shop;
            $itemId = (int) $group->first()->item_id;
            try {
                abort_if(! $shop || $shop->is_demo, 422, 'Toko contoh tidak terhubung ke Shopee.');
                $variations = $group->filter(fn ($l) => (int) $l->model_id > 0);
                $plain = $group->first(fn ($l) => (int) $l->model_id === 0);
                if ($plain) {
                    $api->updateItem($shop, $itemId, ['item_sku' => $skus[$plain->id]]);
                    $plain->update(['sku' => $skus[$plain->id], 'item_sku' => $skus[$plain->id]]);
                    $updated++;
                }
                if ($variations->isNotEmpty()) {
                    $errs = collect();
                    foreach ($variations->chunk(50) as $chunk) {
                        $res = $api->call($shop, 'POST', '/api/v2/product/update_model', ['item_id' => $itemId,
                            'model' => $chunk->map(fn ($l) => ['model_id' => (int) $l->model_id, 'model_sku' => $skus[$l->id]])->values()->all()]);
                        foreach ($res['response']['failure_list'] ?? [] as $f) {
                            $errs[(int) ($f['model_id'] ?? 0)] = (string) ($f['failed_reason'] ?? 'ditolak Shopee');
                        }
                    }
                    foreach ($variations as $l) {
                        if (isset($errs[(int) $l->model_id])) {
                            $failed[$l->id] = $errs[(int) $l->model_id];
                        } else {
                            $l->update(['sku' => $skus[$l->id]]);
                            $updated++;
                        }
                    }
                    // SKU induk: the common front of the variation SKUs (e.g. CKA from CKA-HIT-OLD), only when empty.
                    if (! $group->first()->item_sku) {
                        $parts = $variations->map(fn ($l) => explode('-', $skus[$l->id]))->values();
                        $common = [];
                        foreach ($parts[0] as $i => $p) {
                            if ($parts->every(fn ($x) => ($x[$i] ?? null) === $p)) {
                                $common[] = $p;
                            } else {
                                break;
                            }
                        }
                        if ($common) {
                            try {
                                $api->updateItem($shop, $itemId, ['item_sku' => implode('-', $common)]);
                                MarketplaceListing::where('marketplace_shop_id', $shop->id)->where('item_id', $itemId)->update(['item_sku' => implode('-', $common)]);
                            } catch (Throwable) {
                                // the variation SKUs are what matters
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                foreach ($group as $l) {
                    $failed[$l->id] ??= method_exists($e, 'getMessage') ? $e->getMessage() : 'Gagal';
                }
            }
        }
        // New SKUs may now match an existing Stok Master.
        foreach ($listings->whereNotIn('id', array_keys($failed)) as $l) {
            try {
                \App\Support\MasterStock::autoAttach($l->fresh());
            } catch (Throwable) {
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * One shared stock per SKU: listings that now carry the same SKU are joined in a Stok Master with the stock the
     * seller entered, which is then pushed to every shop. @param array<string, int> $stocks SKU => stock
     * @return int masters set
     */
    public static function shareStock(\App\Models\User $user, array $stocks): int
    {
        $n = 0;
        foreach ($stocks as $sku => $stock) {
            $norm = \App\Support\MasterStock::norm((string) $sku);
            $listings = MarketplaceListing::where('user_id', $user->id)->whereNotNull('sku')->get()
                ->filter(fn ($l) => \App\Support\MasterStock::norm($l->sku) === $norm);
            if ($norm === '' || $listings->isEmpty()) {
                continue;
            }
            $master = \App\Support\MasterStock::find($user, (string) $sku);
            if (! $master) {
                $first = $listings->first();
                $master = new \App\Models\StockMaster(['sku' => trim((string) $sku), 'name' => $first->name.($first->model_name ? " · {$first->model_name}" : ''),
                    'image' => $first->image, 'stock' => max(0, (int) $stock), 'tracking_since' => now(), 'push_pending' => true]);
                $master->user_id = $user->id;
                $master->save();
            }
            foreach ($listings as $l) {
                if ($l->stock_master_id !== $master->id) {
                    \App\Support\MasterStock::attach($master, $l);
                }
            }
            \App\Support\MasterStock::setStock($master, max(0, (int) $stock), 'Stok diatur saat Buat SKU');
            try {
                \App\Support\MasterStock::push($master->refresh());
            } catch (Throwable) {
                // stays push_pending; the scheduler retries
            }
            $n++;
        }

        return $n;
    }
}
