<?php

namespace App\Support;

use App\Models\DropshipProduct;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Dropship catalog numbers from real orders, and the warehouse side of an order: stock counted down from orders
 * since the admin last set it, "terjual 30 hari" from paid orders, wholesale cost (modal) per order, and a
 * notification to the Pesanin admins when an order is paid and waits for the warehouse.
 */
class Dropship
{
    /** Orders that hold stock: not abandoned, not cancelled/refunded. */
    private static function counted()
    {
        return Order::query()->whereNotNull('dropship_product_id')->where('is_abandoned', false)->whereNotIn('status', ['cancelled', 'refunded']);
    }

    public static function available(DropshipProduct $p): int
    {
        $used = (int) self::counted()->where('dropship_product_id', $p->id)
            ->when($p->stock_set_at, fn ($q) => $q->where('created_at', '>=', $p->stock_set_at))->sum('quantity');

        return max(0, (int) $p->stock - $used);
    }

    /** @return array<int, array{available:int, sold_30d:int}> keyed by catalog product id */
    public static function stats(iterable $products): array
    {
        $ids = collect($products)->pluck('id')->all();
        $sold = self::counted()->whereIn('dropship_product_id', $ids)->where('payment_status', 'paid')->where('created_at', '>=', now()->subDays(30))
            ->groupBy('dropship_product_id')->select('dropship_product_id', DB::raw('SUM(quantity) q'))->pluck('q', 'dropship_product_id');

        return collect($products)->mapWithKeys(fn (DropshipProduct $p) => [$p->id => ['available' => self::available($p), 'sold_30d' => (int) ($sold[$p->id] ?? 0)]])->all();
    }

    /** Wholesale cost of an order line: the variant's own wholesale price when set, else the product's. */
    public static function cost(DropshipProduct $p, ?string $variant, int $qty): int
    {
        $v = collect($p->variants ?? [])->firstWhere('name', $variant);
        $unit = (int) (($v['wholesale_price'] ?? null) ?: $p->wholesale_price);

        return $unit * $qty;
    }

    /** Paid dropship order: tell every Pesanin admin it is ready for the warehouse. */
    public static function notifyPaid(Order $order): void
    {
        $order->loadMissing('user:id,store_name');
        foreach (User::where('is_admin', true)->pluck('id') as $adminId) {
            Notifier::send($adminId, 'dropship', 'Pesanan dropship siap dikirim',
                "{$order->code} dari {$order->user?->store_name}: {$order->quantity} pcs, kirim ke {$order->city}.", '/admin/dropship/orders', null);
        }
    }
}
