<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\StockMaster;
use App\Models\Supplier;
use App\Models\User;

/**
 * Saran Restock → draf PO: everything that runs out within the supplier lead time (+ a week), with how many to buy,
 * grouped by the supplier it was last bought from and priced at the last purchase price (else HPP). Quantities
 * already on an open PO (draft / ordered) are subtracted so nothing is ordered twice.
 *   Shopee: Stok Master SKUs (RestockForecast).
 *   Web/medsos: products with stock tracking, sales from web orders of the last 30 days, same formula.
 */
class RestockDraft
{
    /** @return array{groups: array<int, array{supplier: array|null, items: array<int, array>, total: int}>, settings: array} */
    public static function suggestions(User $user): array
    {
        $cfg = RestockForecast::settings($user);
        $items = [];

        $masters = StockMaster::where('user_id', $user->id)->get();
        $sold = RestockForecast::sold($masters->pluck('id')->all());
        foreach ($masters as $m) {
            $f = RestockForecast::forecast($m, $sold[$m->id] ?? 0, $cfg);
            if ($f['restock_qty'] > 0) {
                $items[] = ['type' => 'stock_master', 'id' => $m->id, 'name' => $m->name ?: $m->sku, 'sku' => $m->sku, 'stock' => (int) $m->stock,
                    'channel' => 'Shopee', 'hpp' => Purchasing::masterHpp($m)] + $f;
            }
        }

        $products = Product::where('user_id', $user->id)->where('type', '!=', 'digital')->where('track_stock', true)->whereNull('dropship_product_id')->get();
        $webSold = Order::where('user_id', $user->id)->where('channel', '!=', 'shopee')->where('is_abandoned', false)
            ->whereIn('product_id', $products->pluck('id'))->whereNotIn('status', ['cancelled', 'refunded'])
            ->where('created_at', '>=', now()->subDays(RestockForecast::WINDOW))
            ->selectRaw('product_id, SUM(quantity) as units')->groupBy('product_id')->pluck('units', 'product_id');
        foreach ($products as $p) {
            // Same rules as Stok Master; a product row stands in for the master (stock + tracking start).
            $f = RestockForecast::forecast(new StockMaster(['stock' => (int) $p->stock, 'created_at' => $p->created_at]), (int) ($webSold[$p->id] ?? 0), $cfg);
            if ($f['restock_qty'] > 0) {
                $items[] = ['type' => 'product', 'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'stock' => (int) $p->stock,
                    'channel' => 'Web/medsos', 'hpp' => (int) $p->cost_price] + $f;
            }
        }

        if (! $items) {
            return ['groups' => [], 'settings' => $cfg];
        }

        // Last purchase of each item (supplier + price), and what is already on its way.
        $lines = PurchaseOrderItem::query()->join('purchase_orders as po', 'po.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('po.user_id', $user->id)->where('po.status', '!=', 'cancelled')
            ->orderByDesc('po.ordered_on')->orderByDesc('po.id')
            ->get(['purchase_order_items.stock_master_id', 'purchase_order_items.product_id', 'purchase_order_items.qty', 'purchase_order_items.unit_cost', 'po.supplier_id', 'po.status']);
        $key = fn ($type, $id) => "{$type}:{$id}";
        $last = [];
        $onOrder = [];
        foreach ($lines as $l) {
            $k = $l->stock_master_id ? $key('stock_master', $l->stock_master_id) : $key('product', $l->product_id);
            // Newest line wins, but a supplier from an older PO beats a newer line without one.
            if (! isset($last[$k]) || (! $last[$k]['supplier_id'] && $l->supplier_id)) {
                $last[$k] = ['supplier_id' => $l->supplier_id, 'unit_cost' => (int) $l->unit_cost];
            }
            if (in_array($l->status, ['draft', 'ordered'], true)) {
                $onOrder[$k] = ($onOrder[$k] ?? 0) + (int) $l->qty;
            }
        }

        $suppliers = Supplier::where('user_id', $user->id)->get()->keyBy('id');
        $groups = [];
        foreach ($items as $it) {
            $k = $key($it['type'], $it['id']);
            $it['on_order'] = $onOrder[$k] ?? 0;
            $it['qty'] = max(0, $it['restock_qty'] - $it['on_order']);
            if ($it['qty'] <= 0) {
                continue;
            }
            $sid = $last[$k]['supplier_id'] ?? null;
            $sid = $sid && $suppliers->has($sid) ? (int) $sid : null;
            $it['unit_cost'] = $last[$k]['unit_cost'] ?? $it['hpp'];
            $it['last_bought'] = isset($last[$k]);
            $groups[$sid ?? 0] ??= ['supplier' => $sid ? $suppliers[$sid]->only(['id', 'name', 'phone', 'payment_terms_days']) : null, 'items' => [], 'total' => 0];
            $groups[$sid ?? 0]['items'][] = $it;
            $groups[$sid ?? 0]['total'] += $it['qty'] * $it['unit_cost'];
        }
        $order = ['habis' => 0, 'kritis' => 1, 'segera' => 2];
        foreach ($groups as &$g) {
            usort($g['items'], fn ($a, $b) => [$order[$a['status']] ?? 3, $a['days_left'] ?? -1] <=> [$order[$b['status']] ?? 3, $b['days_left'] ?? -1]);
        }
        unset($g);
        // Known suppliers first, "Tanpa supplier" last.
        uksort($groups, fn ($a, $b) => [$a === 0, $a] <=> [$b === 0, $b]);

        return ['groups' => array_values($groups), 'settings' => $cfg];
    }
}
