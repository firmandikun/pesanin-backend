<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceItemStat;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shopee product tools on top of the official API:
 *  - Naikkan Produk otomatis: Shopee lets a shop boost 5 products at a time, each for 4 hours. Pesanin keeps a
 *    rotation list per shop and refills the free slots every few minutes, so the seller never has to click it.
 *  - Statistik produk: views / likes / units sold per product, snapshotted daily (7-day change on the products page).
 *  - Ubah harga, judul/deskripsi, arsip/tampilkan massal.
 */
class ShopeeProductTools
{
    public const BOOST_SLOTS = 5;

    public function __construct(private ShopeeClient $api = new ShopeeClient) {}

    public static function boostConfig(MarketplaceShop $shop): array
    {
        return ($shop->boost ?? []) + ['enabled' => false, 'items' => [], 'cursor' => 0, 'last_at' => null, 'last_error' => null, 'boosted' => []];
    }

    /** Fill the free boost slots from the rotation list. @return array the updated config */
    public function boost(MarketplaceShop $shop, bool $force = false): array
    {
        $cfg = self::boostConfig($shop);
        if ((! $cfg['enabled'] && ! $force) || ! $cfg['items']) {
            return $cfg;
        }
        try {
            $active = $this->api->boostedList($shop);
            $free = self::BOOST_SLOTS - count($active);
            // Only products still live can be boosted.
            $live = MarketplaceListing::where('marketplace_shop_id', $shop->id)->where('status', 'NORMAL')->distinct()->pluck('item_id')->map(fn ($i) => (int) $i)->all();
            $items = array_values(array_filter(array_map('intval', $cfg['items']), fn ($i) => in_array($i, $live, true)));
            $picked = [];
            $cursor = (int) $cfg['cursor'];
            for ($n = 0; $free > 0 && $n < count($items); $n++) {
                $id = $items[($cursor + $n) % count($items)];
                if (! isset($active[$id]) && ! in_array($id, $picked, true)) {
                    $picked[] = $id;
                    $free--;
                }
            }
            $failed = $picked ? $this->api->boostItems($shop, $picked) : [];
            $ok = array_values(array_diff($picked, array_keys($failed)));
            $cfg = array_merge($cfg, [
                'cursor' => $items ? ($cursor + max(1, count($picked))) % count($items) : 0,
                'last_at' => now()->toIso8601String(),
                'last_error' => $failed ? collect($failed)->map(fn ($r, $id) => "{$id}: {$r}")->implode('; ') : null,
                'boosted' => array_values(array_unique([...array_keys($active), ...$ok])),
            ]);
        } catch (Throwable $e) {
            $cfg['last_error'] = mb_substr($e->getMessage(), 0, 250);
            $cfg['last_at'] = now()->toIso8601String();
        }
        $shop->update(['boost' => $cfg]);

        return $cfg;
    }

    /** Today's views / likes / sales of every product of the shop. @return int products stored */
    public function syncStats(MarketplaceShop $shop): int
    {
        $ids = MarketplaceListing::where('marketplace_shop_id', $shop->id)->whereIn('status', ['NORMAL', 'UNLIST'])->distinct()->pluck('item_id')->all();
        $today = now('Asia/Jakarta')->toDateString();
        $count = 0;
        foreach (array_chunk($ids, 50) as $chunk) {
            foreach ($this->api->itemExtraInfo($shop, $chunk) as $i) {
                MarketplaceItemStat::updateOrCreate(
                    ['marketplace_shop_id' => $shop->id, 'item_id' => (int) $i['item_id'], 'date' => $today],
                    ['views' => (int) ($i['views'] ?? 0), 'likes' => (int) ($i['likes'] ?? 0), 'sales' => (int) ($i['sale'] ?? 0),
                        'rating' => isset($i['rating_star']) ? round((float) $i['rating_star'], 2) : null, 'comments' => (int) ($i['comment_count'] ?? 0)],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Latest numbers and the change over the last 7 days for the given products.
     * @param  Collection<int, array{0: int, 1: int}>  $keys  [shop_id, item_id]
     * @return array<string, array> "shop:item" => {views, likes, sales, rating, comments, views_7d, sales_7d, date}
     */
    public static function statsFor(Collection $keys): array
    {
        if ($keys->isEmpty()) {
            return [];
        }
        $from = now('Asia/Jakarta')->subDays(7)->toDateString();
        $rows = MarketplaceItemStat::where('date', '>=', $from)
            ->where(fn ($w) => $keys->each(fn ($k) => $w->orWhere(fn ($x) => $x->where('marketplace_shop_id', $k[0])->where('item_id', $k[1]))))
            ->orderBy('date')->get()->groupBy(fn ($r) => "{$r->marketplace_shop_id}:{$r->item_id}");

        return $rows->map(function ($g) {
            $first = $g->first();
            $last = $g->last();
            $span = $g->count() > 1;

            return [
                'views' => $last->views, 'likes' => $last->likes, 'sales' => $last->sales, 'rating' => $last->rating ? (float) $last->rating : null,
                'comments' => $last->comments, 'date' => $last->date,
                // Totals only grow; the difference is the activity since the oldest snapshot of the week.
                'views_7d' => $span ? max(0, $last->views - $first->views) : null,
                'sales_7d' => $span ? max(0, $last->sales - $first->sales) : null,
                'days' => $span ? (int) \Carbon\Carbon::parse($first->date)->diffInDays($last->date) : 0,
            ];
        })->all();
    }

    /**
     * New prices for listings (variations). Grouped per product: one Shopee call each.
     * @param  array<int, int>  $prices  listing id => new price
     * @return array{updated: int, failed: array<int, string>} failed listing id => reason
     */
    public function updatePrices(int $userId, array $prices): array
    {
        $listings = MarketplaceListing::where('user_id', $userId)->whereIn('id', array_keys($prices))->with('shop')->get();
        $updated = 0;
        $failed = [];
        foreach ($listings->groupBy(fn ($l) => "{$l->marketplace_shop_id}:{$l->item_id}") as $group) {
            $first = $group->first();
            try {
                $errors = $this->api->updatePrice($first->shop, (int) $first->item_id, $group->mapWithKeys(fn ($l) => [(int) $l->model_id => $prices[$l->id]])->all());
                foreach ($group as $l) {
                    if (isset($errors[(int) $l->model_id])) {
                        $failed[$l->id] = $errors[(int) $l->model_id];
                    } else {
                        $l->update(['price' => $prices[$l->id]]);
                        $updated++;
                    }
                }
            } catch (Throwable $e) {
                foreach ($group as $l) {
                    $failed[$l->id] = $e->getMessage();
                }
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Hide (arsipkan) or show products again in Shopee.
     * @param  array<int, array{0: int, 1: int}>  $items  [shop row id, item_id]
     * @return array{updated: int, failed: array<string, string>} failed "shop:item" => reason
     */
    public function setUnlisted(int $userId, array $items, bool $unlist): array
    {
        $shops = MarketplaceShop::where('user_id', $userId)->whereIn('id', array_unique(array_column($items, 0)))->get()->keyBy('id');
        $updated = 0;
        $failed = [];
        foreach (collect($items)->groupBy(0) as $shopId => $rows) {
            $shop = $shops[$shopId] ?? null;
            if (! $shop) {
                continue;
            }
            foreach ($rows->pluck(1)->map(fn ($i) => (int) $i)->unique()->chunk(50) as $chunk) {
                try {
                    $errors = $this->api->unlistItems($shop, $chunk->mapWithKeys(fn ($id) => [$id => $unlist])->all());
                } catch (Throwable $e) {
                    $errors = $chunk->mapWithKeys(fn ($id) => [$id => $e->getMessage()])->all();
                }
                foreach ($chunk as $id) {
                    if (isset($errors[$id])) {
                        $failed["{$shopId}:{$id}"] = $errors[$id];
                    } else {
                        MarketplaceListing::where('marketplace_shop_id', $shopId)->where('item_id', $id)->update(['status' => $unlist ? 'UNLIST' : 'NORMAL']);
                        $updated++;
                    }
                }
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** Title and description as Shopee has them now (description is not kept by the product sync). */
    public function itemText(MarketplaceShop $shop, int $itemId): array
    {
        $i = $this->api->itemBaseInfo($shop, [$itemId])[0] ?? null;
        abort_unless($i, 404, 'Produk tidak ditemukan di Shopee.');
        $extended = ($i['description_type'] ?? 'normal') === 'extended';
        $text = $extended
            ? collect($i['description_info']['extended_description']['field_list'] ?? [])->where('field_type', 'text')->pluck('text')->implode("\n")
            : (string) ($i['description'] ?? '');

        return ['name' => (string) ($i['item_name'] ?? ''), 'description' => $text, 'extended' => $extended,
            'has_images' => $extended && collect($i['description_info']['extended_description']['field_list'] ?? [])->contains('field_type', 'image')];
    }

    /** Update title and (plain text) description. Description with images is kept: only the title is changed then. */
    public function updateText(MarketplaceShop $shop, int $itemId, string $name, ?string $description): void
    {
        $fields = ['item_name' => $name];
        if ($description !== null) {
            $fields['description'] = $description;
        }
        $this->api->updateItem($shop, $itemId, $fields);
        MarketplaceListing::where('marketplace_shop_id', $shop->id)->where('item_id', $itemId)->update(['name' => mb_substr($name, 0, 255)]);
    }

    /** Every boost-enabled shop, for the scheduler. */
    public static function runAllBoosts(): int
    {
        $n = 0;
        foreach (MarketplaceShop::where('status', 'active')->where('is_demo', false)->whereNotNull('boost')->get() as $shop) {
            if (self::boostConfig($shop)['enabled']) {
                try {
                    (new self)->boost($shop);
                    $n++;
                } catch (Throwable $e) {
                    Log::warning("Naikkan produk {$shop->id}: {$e->getMessage()}");
                }
            }
        }

        return $n;
    }
}
