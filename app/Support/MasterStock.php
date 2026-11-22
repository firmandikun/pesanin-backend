<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\Order;
use App\Models\StockMaster;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Shopee\ShopeeClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stok master lintas toko. Every Shopee listing (in any of the seller's shops) with the same SKU shares one stock:
 *  - a new order in any shop deducts it once (ledger: stock_movements, unique per order + master + kind);
 *  - a cancelled order puts it back once (Shopee restores its own listing too);
 *  - returns are NOT restocked automatically (the goods may not be sellable), the seller adjusts by hand;
 *  - orders created before the master existed are ignored (they are already reflected in the starting stock);
 *  - after any change the master stock is pushed to every linked listing (pushPending, every 30s).
 */
class MasterStock
{
    public static function norm(?string $sku): string
    {
        return mb_strtolower(trim((string) $sku));
    }

    /**
     * Create masters for SKUs used by at least $min listings without a master yet, OR any unlinked listing
     * that already has a SKU and HPP filled. Also syncs HPP across all listings sharing the same master.
     * @return array{masters: int, listings: int}
     */
    public static function generate(User $user, int $min = 2): array
    {
        $groups = MarketplaceListing::where('user_id', $user->id)->whereNull('stock_master_id')
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->whereHas('shop', fn ($q) => $q->where('status', '!=', 'disconnected'))
            ->with('product:id,cost_price')
            ->get()
            ->groupBy(fn ($l) => self::norm($l->sku))
            ->filter(fn ($g, $k) => $k !== '' && ($g->count() >= $min || $g->contains(fn ($l) => ($l->cost_price ?: $l->product?->cost_price) > 0)));
        [$masters, $linked] = [0, 0];
        foreach ($groups as $group) {
            $first = $group->first();
            $master = self::find($user, $first->sku);
            if (! $master) {
                $master = new StockMaster(['sku' => trim($first->sku), 'name' => $first->name.($first->model_name ? " · {$first->model_name}" : ''),
                    'image' => $first->image, 'stock' => max(0, (int) $group->min('stock')), 'tracking_since' => now(), 'push_pending' => true]);
                $master->user_id = $user->id;
                $master->save();
                $masters++;
            }
            MarketplaceListing::whereIn('id', $group->pluck('id'))->update(['stock_master_id' => $master->id]);
            $master->update(['push_pending' => true]);
            $linked += $group->count();

            // Propagate HPP if at least one listing in this master has cost_price / product.cost_price
            $hpp = (int) MarketplaceListing::where('stock_master_id', $master->id)
                ->with('product:id,cost_price')->get(['id', 'cost_price', 'product_id'])
                ->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max();
            if ($hpp > 0) {
                MarketplaceListing::where('stock_master_id', $master->id)
                    ->where(fn ($w) => $w->whereNull('cost_price')->orWhere('cost_price', '<=', 0))
                    ->update(['cost_price' => $hpp]);
            }
        }

        return ['masters' => $masters, 'listings' => $linked];
    }

    public static function find(User|int $user, ?string $sku): ?StockMaster
    {
        $sku = self::norm($sku);

        return $sku === '' ? null : StockMaster::where('user_id', is_int($user) ? $user : $user->id)->whereRaw('LOWER(TRIM(sku)) = ?', [$sku])->first();
    }

    /** A synced/new listing whose SKU already has a master joins it, inherits HPP, and queues/pushes master stock. */
    public static function autoAttach(MarketplaceListing $listing, bool $pushNow = false): ?StockMaster
    {
        if (self::norm($listing->sku) === '') {
            return null;
        }
        $master = self::find($listing->user_id, $listing->sku);
        if (! $master) {
            return null;
        }
        if ($listing->stock_master_id !== $master->id) {
            $listing->update(['stock_master_id' => $master->id, 'stock_push_error' => null]);
            $master->update(['push_pending' => true]);
        }
        self::syncHpp($master);
        if ($pushNow) {
            try {
                self::push($master->refresh());
            } catch (Throwable) {
                // stays push_pending; scheduler retries
            }
        }

        return $master;
    }

    public static function attach(StockMaster $master, MarketplaceListing $listing): void
    {
        $listing->update(['stock_master_id' => $master->id, 'stock_push_error' => null]);
        $master->update(['push_pending' => true]);
        self::syncHpp($master);
    }

    /** Copy known HPP (> 0) from any listing in this master to linked listings that still have no HPP. */
    public static function syncHpp(StockMaster $master): void
    {
        $hpp = $master->listings()
            ->with('product:id,cost_price')
            ->get(['id', 'cost_price', 'product_id'])
            ->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))
            ->filter()
            ->max();
        if (! $hpp) {
            return;
        }
        $master->listings()
            ->where(fn ($w) => $w->whereNull('cost_price')->orWhere('cost_price', '<=', 0))
            ->update(['cost_price' => $hpp]);
    }

    /** Set the stock by hand (stock opname / restock / return); recorded in the history and pushed to every shop. */
    public static function setStock(StockMaster $master, int $stock, ?string $note = null): StockMaster
    {
        DB::transaction(function () use ($master, $stock, $note) {
            $m = StockMaster::whereKey($master->id)->lockForUpdate()->first();
            $diff = $stock - $m->stock;
            if ($diff !== 0) {
                StockMovement::create(['stock_master_id' => $m->id, 'kind' => 'adjust', 'qty' => $diff, 'stock_after' => $stock, 'note' => $note ? mb_substr($note, 0, 190) : null]);
            }
            $m->update(['stock' => $stock, 'push_pending' => true]);
        });

        return $master->refresh();
    }

    /**
     * Apply a (new or updated) Shopee order: deduct its items from their masters once; give them back once when the
     * order is cancelled. Cheap no-op for sellers without masters.
     */
    public static function applyOrder(Order $order): void
    {
        if ($order->channel !== 'shopee' || ! $order->marketplace_shop_id || empty($order->items)
            || ! StockMaster::where('user_id', $order->user_id)->exists()) {
            return;
        }
        // Qty per master; a listing is matched by shop + item + model (falling back to the item's single row).
        $perMaster = [];
        foreach ($order->items as $i) {
            $listing = MarketplaceListing::where('marketplace_shop_id', $order->marketplace_shop_id)->where('item_id', $i['item_id'] ?? 0)
                ->where('model_id', $i['model_id'] ?? 0)->whereNotNull('stock_master_id')->first();
            if ($listing) {
                $perMaster[$listing->stock_master_id] = ($perMaster[$listing->stock_master_id] ?? 0) + (int) ($i['qty'] ?? 1);
            }
        }
        $cancelled = $order->external_status === 'CANCELLED';
        foreach ($perMaster as $masterId => $qty) {
            try {
                DB::transaction(function () use ($masterId, $qty, $order, $cancelled) {
                    $m = StockMaster::whereKey($masterId)->lockForUpdate()->first();
                    if (! $m || ($m->tracking_since && $order->created_at && $order->created_at->lt($m->tracking_since))) {
                        return;
                    }
                    $done = StockMovement::where('stock_master_id', $m->id)->where('order_id', $order->id)->pluck('kind')->all();
                    if (! $cancelled && ! in_array('sale', $done, true)) {
                        self::move($m, $order, 'sale', -$qty, "Pesanan {$order->external_order_sn}");
                    } elseif ($cancelled && in_array('sale', $done, true) && ! in_array('restore', $done, true)) {
                        self::move($m, $order, 'restore', $qty, "Batal {$order->external_order_sn}");
                    }
                });
            } catch (UniqueConstraintViolationException) {
                // Another sync applied it at the same moment.
            }
        }
    }

    private static function move(StockMaster $m, Order $order, string $kind, int $qty, string $note): void
    {
        $after = $m->stock + $qty;
        StockMovement::create(['stock_master_id' => $m->id, 'order_id' => $order->id, 'kind' => $kind, 'qty' => $qty, 'stock_after' => $after, 'note' => $note]);
        $m->update(['stock' => $after, 'push_pending' => true]);
    }

    /** Push every master with a pending change (oldest first). @return int masters pushed */
    public static function pushPending(int $limit = 50): int
    {
        $masters = StockMaster::where('push_pending', true)->orderBy('updated_at')->limit($limit)->get();
        foreach ($masters as $m) {
            self::push($m);
        }

        return $masters->count();
    }

    /**
     * Set the master stock on all linked listings, one call per Shopee item. Failures are kept on the listing
     * (shown in the page) instead of retrying forever; "Dorong ulang" pushes again.
     */
    public static function push(StockMaster $m): void
    {
        $m->update(['push_pending' => false]);
        $stock = max(0, $m->stock);
        $api = new ShopeeClient;
        $listings = $m->listings()->with('shop')->get()->filter(fn ($l) => $l->shop && $l->shop->status === 'active');
        foreach ($listings->groupBy(fn ($l) => $l->marketplace_shop_id.':'.$l->item_id) as $group) {
            $shop = $group->first()->shop;
            if ($shop->is_demo) {
                MarketplaceListing::whereIn('id', $group->pluck('id'))->update(['stock' => $stock, 'stock_push_error' => null]);

                continue;
            }
            try {
                $failed = $api->updateStocks($shop, (int) $group->first()->item_id, $group->mapWithKeys(fn ($l) => [(int) $l->model_id => $stock])->all());
                foreach ($group as $l) {
                    $err = $failed[(int) $l->model_id] ?? null;
                    $l->update($err ? ['stock_push_error' => mb_substr($err, 0, 190)] : ['stock' => $stock, 'stock_push_error' => null]);
                }
            } catch (Throwable $e) {
                MarketplaceListing::whereIn('id', $group->pluck('id'))->update(['stock_push_error' => mb_substr($e->getMessage(), 0, 190)]);
            }
        }
        $m->update(['pushed_at' => now()]);
    }
}
