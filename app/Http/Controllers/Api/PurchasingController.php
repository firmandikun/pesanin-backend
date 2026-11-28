<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchasePayment;
use App\Models\StockMaster;
use App\Models\Supplier;
use App\Support\Purchasing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pembelian & Supplier: suppliers, purchase orders, receiving goods (stock + HPP) and paying suppliers. */
class PurchasingController extends Controller
{
    /* ── Suppliers ──────────────────────────────────────────────────────────────── */

    private function supplierRules(): array
    {
        return ['name' => 'required|string|max:120', 'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s-]{6,20}$/'], 'email' => 'nullable|email|max:120',
            'address' => 'nullable|string|max:300', 'payment_terms_days' => 'nullable|integer|min:0|max:180', 'notes' => 'nullable|string|max:2000'];
    }

    public function suppliers(Request $request)
    {
        $uid = $request->user()->id;
        $owed = PurchaseOrder::where('user_id', $uid)->whereIn('status', ['ordered', 'received'])
            ->selectRaw('supplier_id, SUM(total - paid) as owed, COUNT(*) as orders')->groupBy('supplier_id')->get()->keyBy('supplier_id');

        return ['data' => Supplier::where('user_id', $uid)->orderBy('name')->get()->map(fn ($s) => $s->toArray() + [
            'owed' => (int) max(0, $owed[$s->id]->owed ?? 0), 'orders' => (int) ($owed[$s->id]->orders ?? 0),
        ])];
    }

    public function storeSupplier(Request $request)
    {
        $s = new Supplier($request->validate($this->supplierRules()));
        $s->user_id = $request->user()->id;
        $s->save();

        return response()->json($s, 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier)
    {
        abort_unless($supplier->user_id === $request->user()->id, 404);
        $supplier->update($request->validate($this->supplierRules()));

        return $supplier;
    }

    public function deleteSupplier(Request $request, Supplier $supplier)
    {
        abort_unless($supplier->user_id === $request->user()->id, 404);
        $supplier->delete(); // its POs stay, without supplier

        return response()->noContent();
    }

    /* ── Purchase orders ────────────────────────────────────────────────────────── */

    /** Saran Restock: what to buy now, grouped by the supplier it was last bought from (ready for draft POs). */
    public function restock(Request $request)
    {
        return \App\Support\RestockDraft::suggestions($request->user());
    }

    /** What can be bought: Shopee SKUs (Stok Master) and web/social products, with stock and HPP. */
    public function catalog(Request $request)
    {
        $uid = $request->user()->id;
        $q = trim((string) $request->query('q', ''));
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $masters = StockMaster::where('user_id', $uid)->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('sku', 'like', $like)->orWhere('name', 'like', $like)))
            ->orderBy('name')->limit(30)->get()->map(fn ($m) => ['type' => 'stock_master', 'id' => $m->id, 'name' => $m->name ?: $m->sku, 'sku' => $m->sku,
                'stock' => (int) $m->stock, 'hpp' => Purchasing::masterHpp($m), 'channel' => 'Shopee (Stok Master)']);
        $products = Product::where('user_id', $uid)->where('type', '!=', 'digital')->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('sku', 'like', $like)))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'sku', 'stock', 'track_stock', 'cost_price'])->map(fn ($p) => ['type' => 'product', 'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'stock' => $p->track_stock ? (int) $p->stock : null, 'hpp' => (int) $p->cost_price, 'channel' => 'Produk web/medsos']);

        return ['items' => $masters->concat($products)->values()];
    }

    private function present(PurchaseOrder $po, bool $full = false): array
    {
        $po->loadMissing('supplier:id,name,phone');
        $today = now(\App\Support\Dates::TZ)->toDateString();

        return $po->toArray() + [
            'balance' => $po->balance, 'payment_status' => $po->payment_status,
            'overdue' => $po->balance > 0 && $po->due_on && $po->due_on->toDateString() < $today,
            'status_label' => PurchaseOrder::STATUSES[$po->status] ?? $po->status,
        ] + ($full ? ['items' => $po->items()->get(), 'payments' => $po->payments()->get()] : []);
    }

    public function index(Request $request)
    {
        $d = $request->validate(['status' => 'nullable|in:draft,ordered,received,cancelled,unpaid', 'supplier_id' => 'nullable|integer', 'q' => 'nullable|string|max:60']);
        $uid = $request->user()->id;
        $page = PurchaseOrder::where('user_id', $uid)
            ->when(($d['status'] ?? null) === 'unpaid', fn ($w) => $w->whereIn('status', ['ordered', 'received'])->whereColumn('paid', '<', 'total'))
            ->when(($d['status'] ?? null) && $d['status'] !== 'unpaid', fn ($w) => $w->where('status', $d['status']))
            ->when($d['supplier_id'] ?? null, fn ($w, $s) => $w->where('supplier_id', $s))
            ->when($d['q'] ?? null, fn ($w, $s) => $w->where('number', 'like', "%{$s}%"))
            ->latest('ordered_on')->latest('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn ($po) => $this->present($po)));

        return ['summary' => Purchasing::summary($request->user())] + $page->toArray();
    }

    public function show(Request $request, PurchaseOrder $purchase)
    {
        abort_unless($purchase->user_id === $request->user()->id, 404);

        return $this->present($purchase, true);
    }

    private function orderRules(): array
    {
        return [
            'supplier_id' => 'nullable|integer', 'status' => 'nullable|in:draft,ordered', 'ordered_on' => 'required|date', 'due_on' => 'nullable|date|after_or_equal:ordered_on',
            'discount' => 'nullable|integer|min:0|max:10000000000', 'shipping_cost' => 'nullable|integer|min:0|max:10000000000', 'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:200',
            'items.*.type' => ['required', Rule::in(['stock_master', 'product'])], 'items.*.id' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1|max:1000000', 'items.*.unit_cost' => 'required|integer|min:0|max:10000000000',
        ];
    }

    private function fill(Request $request, PurchaseOrder $po, array $d): PurchaseOrder
    {
        $uid = $request->user()->id;
        if (! empty($d['supplier_id'])) {
            abort_unless(Supplier::where('user_id', $uid)->whereKey($d['supplier_id'])->exists(), 422, 'Supplier tidak ditemukan.');
        }
        $lines = [];
        foreach ($d['items'] as $it) {
            $target = $it['type'] === 'stock_master' ? StockMaster::where('user_id', $uid)->find($it['id']) : Product::where('user_id', $uid)->find($it['id']);
            abort_unless($target, 422, 'Ada produk yang tidak ditemukan. Muat ulang lalu pilih lagi.');
            $lines[] = ['stock_master_id' => $it['type'] === 'stock_master' ? $target->id : null, 'product_id' => $it['type'] === 'product' ? $target->id : null,
                'name' => mb_substr($target->name ?: $target->sku ?: 'Produk', 0, 255), 'sku' => $target->sku, 'qty' => $it['qty'], 'unit_cost' => $it['unit_cost']];
        }
        $po->fill(['supplier_id' => $d['supplier_id'] ?? null, 'status' => $d['status'] ?? 'ordered', 'ordered_on' => $d['ordered_on'], 'due_on' => $d['due_on'] ?? null,
            'discount' => $d['discount'] ?? 0, 'shipping_cost' => $d['shipping_cost'] ?? 0, 'notes' => $d['notes'] ?? null]);
        if (! $po->exists) {
            $po->user_id = $uid;
            $po->number = Purchasing::nextNumber($request->user(), \Carbon\Carbon::parse($d['ordered_on']));
        }
        $po->save();
        $po->items()->delete();
        $po->items()->createMany($lines);
        Purchasing::totals($po);
        abort_if($po->paid > $po->total, 422, 'Total PO lebih kecil dari yang sudah dibayar.');

        return $po;
    }

    public function store(Request $request)
    {
        $po = \DB::transaction(fn () => $this->fill($request, new PurchaseOrder, $request->validate($this->orderRules())));

        return response()->json($this->present($po->fresh(), true), 201);
    }

    public function update(Request $request, PurchaseOrder $purchase)
    {
        abort_unless($purchase->user_id === $request->user()->id, 404);
        abort_unless(in_array($purchase->status, ['draft', 'ordered'], true), 422, 'PO yang sudah diterima atau dibatalkan tidak bisa diubah.');
        $po = \DB::transaction(fn () => $this->fill($request, $purchase, $request->validate($this->orderRules())));

        return $this->present($po->fresh(), true);
    }

    public function receive(Request $request, PurchaseOrder $purchase)
    {
        abort_unless($purchase->user_id === $request->user()->id, 404);
        $changes = Purchasing::receive($purchase);
        \App\Support\MasterStock::pushPending();

        return ['changes' => $changes, 'purchase' => $this->present($purchase->fresh(), true)];
    }

    public function cancel(Request $request, PurchaseOrder $purchase)
    {
        abort_unless($purchase->user_id === $request->user()->id, 404);
        abort_unless(in_array($purchase->status, ['draft', 'ordered'], true), 422, 'Barang PO ini sudah diterima; batalkan lewat penyesuaian stok.');
        abort_if($purchase->paid > 0, 422, 'PO ini sudah dibayar sebagian. Hapus pembayarannya dulu.');
        $purchase->update(['status' => 'cancelled']);

        return $this->present($purchase->fresh(), true);
    }

    public function pay(Request $request, PurchaseOrder $purchase)
    {
        abort_unless($purchase->user_id === $request->user()->id, 404);
        $d = $request->validate(['amount' => 'required|integer|min:1', 'paid_on' => 'required|date|before_or_equal:today',
            'method' => ['required', Rule::in(array_keys(Expense::PAYMENT_METHODS))], 'note' => 'nullable|string|max:200']);
        Purchasing::pay($purchase, $d['amount'], $d['paid_on'], $d['method'], $d['note'] ?? null);

        return $this->present($purchase->fresh(), true);
    }

    public function deletePayment(Request $request, PurchaseOrder $purchase, PurchasePayment $payment)
    {
        abort_unless($purchase->user_id === $request->user()->id && $payment->purchase_order_id === $purchase->id, 404);
        Purchasing::deletePayment($purchase, $payment);

        return $this->present($purchase->fresh(), true);
    }
}
