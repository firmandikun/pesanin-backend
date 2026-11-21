<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\StockMaster;
use App\Support\MasterStock;
use App\Support\Plans;
use Illuminate\Http\Request;

/** Marketplace → Stok Master: one stock per SKU across every Shopee shop. */
class StockMasterController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = StockMaster::where('user_id', $user->id)
            ->with(['listings' => fn ($l) => $l->select('id', 'stock_master_id', 'marketplace_shop_id', 'product_id', 'item_id', 'model_id', 'name', 'model_name', 'sku', 'stock', 'price', 'cost_price', 'stock_push_error', 'image', 'status')
                ->with(['shop:id,shop_name,is_demo,status', 'product:id,cost_price'])])
            ->when($request->query('search'), fn ($w, $s) => $w->where(fn ($x) => $x->where('sku', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->when($request->query('filter') === 'empty', fn ($w) => $w->where('stock', '<=', 0))
            ->when($request->query('filter') === 'error', fn ($w) => $w->whereHas('listings', fn ($l) => $l->whereNotNull('stock_push_error')))
            ->orderBy('sku');
        $listings = MarketplaceListing::where('user_id', $user->id)->whereHas('shop', fn ($s) => $s->where('status', '!=', 'disconnected'));
        // SKUs used by 2+ listings or unlinked listings with SKU + HPP already filled.
        $candidates = (clone $listings)->whereNull('stock_master_id')->whereNotNull('sku')->where('sku', '!=', '')
            ->with('product:id,cost_price')->get(['id', 'sku', 'cost_price', 'product_id'])
            ->groupBy(fn ($l) => MasterStock::norm($l->sku))
            ->filter(fn ($g, $k) => $k !== '' && ($g->count() >= 2 || $g->contains(fn ($l) => ($l->cost_price ?: $l->product?->cost_price) > 0)))->count();

        // Modal di gudang: units still in the warehouse × their HPP (from the linked listings / products), i.e. money
        // that has not turned back into cash yet; plus what it would bring at the current selling price.
        $value = ['stock_value' => 0, 'stock_units' => 0, 'sell_value' => 0, 'no_hpp' => 0];
        StockMaster::where('user_id', $user->id)->where('stock', '>', 0)
            ->with(['listings' => fn ($l) => $l->select('id', 'stock_master_id', 'product_id', 'price', 'cost_price')->with('product:id,cost_price')])
            ->chunk(500, function ($masters) use (&$value) {
                foreach ($masters as $m) {
                    $hpp = $m->listings->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max();
                    $price = (int) round($m->listings->where('price', '>', 0)->avg('price') ?? 0);
                    $value['stock_units'] += $m->stock;
                    $value['sell_value'] += $m->stock * $price;
                    $hpp ? $value['stock_value'] += $m->stock * $hpp : $value['no_hpp']++;
                }
            });

        $mastersPage = $q->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $mastersPage->through(function ($m) {
            $hpp = (int) ($m->listings->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max() ?: 0);
            $sellPrice = (int) round($m->listings->where('price', '>', 0)->avg('price') ?? 0);
            $m->setAttribute('hpp', $hpp);
            $m->setAttribute('sell_price', $sellPrice);

            return $m;
        });

        return [
            'masters' => $mastersPage,
            'stats' => $value + [
                'masters' => StockMaster::where('user_id', $user->id)->count(),
                'linked' => (clone $listings)->whereNotNull('stock_master_id')->count(),
                'unlinked_with_sku' => (clone $listings)->whereNull('stock_master_id')->whereNotNull('sku')->where('sku', '!=', '')->count(),
                'without_sku' => (clone $listings)->where(fn ($w) => $w->whereNull('sku')->orWhere('sku', ''))->count(),
                'candidates' => $candidates,
                'empty' => StockMaster::where('user_id', $user->id)->where('stock', '<=', 0)->count(),
                'errors' => (clone $listings)->whereNotNull('stock_master_id')->whereNotNull('stock_push_error')->count(),
            ],
        ];
    }

    /** "Buat dari SKU yang sama": masters for every SKU shared by 2+ listings (starting stock = the lowest of them). */
    public function generate(Request $request)
    {
        Plans::ensureFeature($request->user(), 'stock_master');
        $r = MasterStock::generate($request->user());
        MasterStock::pushPending();

        return $r;
    }

    /** A master for one listing's SKU (also when only one shop sells it yet), starting from that listing's stock. */
    public function store(Request $request)
    {
        Plans::ensureFeature($request->user(), 'stock_master');
        $d = $request->validate(['listing_id' => 'required|integer']);
        $listing = MarketplaceListing::where('user_id', $request->user()->id)->findOrFail($d['listing_id']);
        abort_if(MasterStock::norm($listing->sku) === '', 422, 'Listing ini belum punya SKU. Isi SKU di Seller Centre dulu, lalu sinkronkan produk.');
        $master = MasterStock::find($request->user(), $listing->sku);
        if (! $master) {
            $master = new StockMaster(['sku' => trim($listing->sku), 'name' => $listing->name.($listing->model_name ? " · {$listing->model_name}" : ''),
                'image' => $listing->image, 'stock' => max(0, $listing->stock), 'tracking_since' => now()]);
            $master->user_id = $request->user()->id;
            $master->save();
        }
        MasterStock::attach($master, $listing);
        MarketplaceListing::where('user_id', $request->user()->id)->whereNull('stock_master_id')->whereRaw('LOWER(TRIM(sku)) = ?', [MasterStock::norm($listing->sku)])
            ->update(['stock_master_id' => $master->id]);
        MasterStock::push($master);

        return $master->load('listings.shop:id,shop_name,is_demo,status');
    }

    public function update(Request $request, StockMaster $master)
    {
        $this->own($request, $master);
        Plans::ensureFeature($request->user(), 'stock_master');
        $d = $request->validate(['stock' => 'required|integer|min:0|max:999999', 'note' => 'nullable|string|max:190']);
        MasterStock::setStock($master, $d['stock'], $d['note'] ?? 'Ubah manual');
        MasterStock::push($master);

        return $master->refresh()->load('listings.shop:id,shop_name,is_demo,status');
    }

    /** Link another listing (e.g. one with a different/empty SKU) to this master. */
    public function attach(Request $request, StockMaster $master)
    {
        $this->own($request, $master);
        Plans::ensureFeature($request->user(), 'stock_master');
        $d = $request->validate(['listing_id' => 'required|integer']);
        $listing = MarketplaceListing::where('user_id', $request->user()->id)->findOrFail($d['listing_id']);
        MasterStock::attach($master, $listing);
        MasterStock::push($master);

        return $master->refresh()->load('listings.shop:id,shop_name,is_demo,status');
    }

    public function detach(Request $request, StockMaster $master, MarketplaceListing $listing)
    {
        $this->own($request, $master);
        abort_unless($listing->stock_master_id === $master->id, 404);
        $listing->update(['stock_master_id' => null, 'stock_push_error' => null]);

        return $master->refresh()->load('listings.shop:id,shop_name,is_demo,status');
    }

    public function push(Request $request, StockMaster $master)
    {
        $this->own($request, $master);
        MasterStock::push($master);

        return $master->refresh()->load('listings.shop:id,shop_name,is_demo,status');
    }

    public function movements(Request $request, StockMaster $master)
    {
        $this->own($request, $master);

        return $master->movements()->latest('id')->limit(50)->get(['id', 'kind', 'qty', 'stock_after', 'note', 'order_id', 'created_at']);
    }

    /** Stop sharing: listings keep their current stock in Shopee and are managed per shop again. */
    public function destroy(Request $request, StockMaster $master)
    {
        $this->own($request, $master);
        MarketplaceListing::where('stock_master_id', $master->id)->update(['stock_master_id' => null, 'stock_push_error' => null]);
        $master->delete();

        return response()->noContent();
    }

    private function own(Request $request, StockMaster $master): void
    {
        abort_unless($master->user_id === $request->user()->id, 404);
    }
}
