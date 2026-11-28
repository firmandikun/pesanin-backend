<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\MarketplaceListing;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchasePayment;
use App\Models\StockMaster;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pembelian & Supplier (phase 1 of the accounting/ERP track).
 *
 *  - A purchase order (PO) lists what is bought from a supplier: Shopee SKUs (Stok Master, shared by every shop)
 *    or web/social products, with the supplier's unit price.
 *  - Receiving it adds the quantities to stock (pushed to Shopee for Stok Master) and updates HPP with the moving
 *    average: (stock on hand × old HPP + received × landed cost) ÷ new stock. Landed cost spreads the PO's shipping
 *    cost and discount over the lines by value, so HPP reflects what the goods really cost.
 *  - Payments reduce what is owed to the supplier (utang) and are mirrored in Pengeluaran as "HPP & Pembelian Stok"
 *    (cash out that does not cut profit: the cost reaches profit through HPP when the goods are sold).
 */
class Purchasing
{
    public static function nextNumber(User $user, \DateTimeInterface $on): string
    {
        $prefix = 'PO-'.$on->format('ym').'-';
        $last = PurchaseOrder::where('user_id', $user->id)->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 3, '0', STR_PAD_LEFT);
    }

    /** Current HPP of a Stok Master: the HPP its listings carry (highest if they differ). */
    public static function masterHpp(StockMaster $m): int
    {
        return (int) $m->listings()->with('product:id,cost_price')->get(['id', 'cost_price', 'product_id'])
            ->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max();
    }

    public static function movingAverage(int $onHand, int $oldHpp, int $qty, int $landed): int
    {
        $onHand = max(0, $onHand);
        if ($onHand === 0 || $oldHpp <= 0) {
            return $landed;
        }

        return (int) round(($onHand * $oldHpp + $qty * $landed) / ($onHand + $qty));
    }

    /** Totals of a PO from its lines. */
    public static function totals(PurchaseOrder $po): void
    {
        $sub = (int) $po->items()->get()->sum(fn ($i) => $i->qty * $i->unit_cost);
        $po->subtotal = $sub;
        $po->total = max(0, $sub - (int) $po->discount + (int) $po->shipping_cost);
        $po->save();
    }

    /**
     * Receive the goods: stock in + HPP (moving average) for every line, once.
     * @return array<int, array{name: string, qty: int, stock_after: ?int, hpp_before: int, hpp_after: int}>
     */
    public static function receive(PurchaseOrder $po): array
    {
        abort_unless(in_array($po->status, ['draft', 'ordered'], true), 422, 'PO ini sudah diterima atau dibatalkan.');

        return DB::transaction(function () use ($po) {
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->first();
            abort_unless(in_array($po->status, ['draft', 'ordered'], true), 422, 'PO ini sudah diterima atau dibatalkan.');
            $items = $po->items()->get();
            $sub = max(1, (int) $items->sum(fn ($i) => $i->qty * $i->unit_cost));
            $extra = (int) $po->shipping_cost - (int) $po->discount; // spread over the lines by value
            $out = [];
            foreach ($items as $i) {
                $share = $i->qty > 0 ? ($i->qty * $i->unit_cost / $sub) * $extra / $i->qty : 0;
                $landed = max(0, (int) round($i->unit_cost + $share));
                $i->update(['landed_unit_cost' => $landed]);

                if ($i->stock_master_id && ($m = StockMaster::where('user_id', $po->user_id)->lockForUpdate()->find($i->stock_master_id))) {
                    $before = self::masterHpp($m);
                    $hpp = self::movingAverage((int) $m->stock, $before, $i->qty, $landed);
                    $stock = (int) $m->stock + $i->qty;
                    StockMovement::create(['stock_master_id' => $m->id, 'kind' => 'purchase', 'qty' => $i->qty, 'stock_after' => $stock, 'note' => "Barang masuk {$po->number}"]);
                    $m->update(['stock' => $stock, 'push_pending' => true]);
                    MarketplaceListing::where('stock_master_id', $m->id)->update(['cost_price' => $hpp]);
                    $out[] = ['name' => $i->name, 'qty' => $i->qty, 'stock_after' => $stock, 'hpp_before' => $before, 'hpp_after' => $hpp];
                } elseif ($i->product_id && ($p = Product::where('user_id', $po->user_id)->lockForUpdate()->find($i->product_id))) {
                    $before = (int) $p->cost_price;
                    $onHand = $p->track_stock ? (int) $p->stock : 0;
                    $hpp = self::movingAverage($onHand, $before, $i->qty, $landed);
                    $p->cost_price = $hpp;
                    if ($p->track_stock) {
                        $p->stock = (int) $p->stock + $i->qty;
                    }
                    $p->save();
                    $out[] = ['name' => $i->name, 'qty' => $i->qty, 'stock_after' => $p->track_stock ? (int) $p->stock : null, 'hpp_before' => $before, 'hpp_after' => $hpp];
                }
            }
            $po->update(['status' => 'received', 'received_at' => now()]);

            return $out;
        });
    }

    /** Pay (part of) a PO; mirrored in Pengeluaran so cash reports stay complete. */
    public static function pay(PurchaseOrder $po, int $amount, string $paidOn, string $method, ?string $note): PurchasePayment
    {
        abort_if($po->status === 'cancelled', 422, 'PO yang dibatalkan tidak bisa dibayar.');
        abort_if($amount < 1 || $amount > $po->balance, 422, 'Jumlah melebihi sisa utang ('.number_format($po->balance, 0, ',', '.').').');

        return DB::transaction(function () use ($po, $amount, $paidOn, $method, $note) {
            $po->loadMissing('supplier');
            $e = new Expense(['spent_on' => $paidOn, 'category' => 'inventory', 'amount' => $amount,
                'description' => mb_substr("Bayar {$po->number}".($po->supplier ? " ({$po->supplier->name})" : ''), 0, 150),
                'vendor' => $po->supplier ? mb_substr($po->supplier->name, 0, 80) : null, 'note' => $note ? mb_substr($note, 0, 255) : null,
                'payment_method' => array_key_exists($method, Expense::PAYMENT_METHODS) ? $method : 'other']);
            $e->user_id = $po->user_id;
            $e->save();
            $pay = $po->payments()->create(['expense_id' => $e->id, 'paid_on' => $paidOn, 'amount' => $amount, 'method' => $method, 'note' => $note]);
            $po->increment('paid', $amount);

            return $pay;
        });
    }

    public static function deletePayment(PurchaseOrder $po, PurchasePayment $pay): void
    {
        DB::transaction(function () use ($po, $pay) {
            if ($pay->expense_id) {
                Expense::whereKey($pay->expense_id)->delete();
            }
            $pay->delete();
            $po->update(['paid' => max(0, $po->paid - $pay->amount)]);
        });
    }

    /** Header numbers of the Pembelian page. */
    public static function summary(User $user): array
    {
        $open = PurchaseOrder::where('user_id', $user->id)->whereIn('status', ['ordered', 'received'])->whereColumn('paid', '<', 'total')
            ->get(['id', 'total', 'paid', 'due_on', 'status']);
        $today = now(Dates::TZ)->toDateString();
        [$from, $to] = [now(Dates::TZ)->startOfMonth()->toDateString(), now(Dates::TZ)->endOfMonth()->toDateString()];

        return [
            'payable' => (int) $open->sum(fn ($p) => $p->total - $p->paid),
            'overdue' => (int) $open->filter(fn ($p) => $p->due_on && $p->due_on->toDateString() < $today)->sum(fn ($p) => $p->total - $p->paid),
            'awaiting_goods' => PurchaseOrder::where('user_id', $user->id)->where('status', 'ordered')->count(),
            'bought_this_month' => (int) PurchaseOrder::where('user_id', $user->id)->where('status', '!=', 'cancelled')->whereBetween('ordered_on', [$from, $to])->sum('total'),
        ];
    }
}
