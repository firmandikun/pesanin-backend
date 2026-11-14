<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Support\Plans;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeDemo;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeSync;
use App\Support\Analytics;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/** Connected Shopee shops: OAuth, sync, listings and stock. */
class MarketplaceController extends Controller
{
    public function shops(Request $request)
    {
        $user = $request->user();
        $shops = $user->marketplaceShops()
            ->withCount(['listings', 'orders', 'orders as orders_today_count' => fn ($q) => $q->whereDate('created_at', today())])
            ->latest()->get();

        return [
            'shops' => $shops,
            'configured' => ShopeeClient::configured(),
            'environment' => str_contains((string) config('services.shopee.host'), 'test-stable') ? 'sandbox' : 'live',
            'redirect_url' => config('services.shopee.redirect'),
            'push_url' => config('services.shopee.push_url'),
            'can_demo' => app()->isLocal(),
        ];
    }

    public function connect(Request $request)
    {
        abort_unless(ShopeeClient::configured(), 422, 'Isi SHOPEE_PARTNER_ID dan SHOPEE_PARTNER_KEY di backend/.env terlebih dahulu.');
        Plans::ensureQuota($request->user(), 'shops');
        $state = Crypt::encryptString(json_encode(['uid' => $request->user()->id, 'exp' => now()->addMinutes(15)->timestamp]));

        return ['url' => (new ShopeeClient)->authUrl($state)];
    }

    /**
     * A link the account owner can send to whoever holds the Shopee login (often the shop owner on another laptop).
     * Valid 24 hours; every open creates a fresh Shopee authorisation URL, since Shopee's own link expires in minutes.
     */
    public function shareLink(Request $request)
    {
        abort_unless(ShopeeClient::configured(), 422, 'Isi SHOPEE_PARTNER_ID dan SHOPEE_PARTNER_KEY di backend/.env terlebih dahulu.');
        Plans::ensureQuota($request->user(), 'shops');
        $token = Crypt::encryptString(json_encode(['uid' => $request->user()->id, 'exp' => now()->addDay()->timestamp, 'share' => true]));

        return ['url' => rtrim(config('app.url'), '/').'/api/shopee/authorize?t='.urlencode($token), 'expires_at' => now()->addDay()];
    }

    /** Public: opens Shopee's authorisation page for the account in a share link. */
    public function authorizeShared(Request $request)
    {
        $done = rtrim(config('services.frontend_url'), '/').'/shopee-terhubung';
        try {
            $t = json_decode(Crypt::decryptString((string) $request->query('t')), true);
            if (! $t || empty($t['share']) || $t['exp'] < time() || ! ($user = User::find($t['uid']))) {
                return redirect($done.'?error='.urlencode('Link sudah kedaluwarsa. Minta link baru ke pemilik akun Pesanin.'));
            }
        } catch (\Throwable) {
            return redirect($done.'?error='.urlencode('Link tidak valid. Minta link baru ke pemilik akun Pesanin.'));
        }
        $limit = Plans::all()[Plans::current($user)]['limits']['shops'];
        if ($limit !== null && Plans::usage($user)['shops'] >= $limit) {
            return redirect($done.'?error='.urlencode('Batas toko di paket akun Pesanin ini sudah tercapai.'));
        }
        $state = Crypt::encryptString(json_encode(['uid' => $user->id, 'exp' => now()->addMinutes(15)->timestamp]));

        return redirect((new ShopeeClient)->authUrl($state));
    }

    /**
     * Shopee redirects here after a shop is authorised. The connection is saved before any page loads, so the
     * landing page is public: whoever logged in to Shopee (maybe on someone else's laptop) needs no Pesanin login.
     */
    public function callback(Request $request)
    {
        $done = rtrim(config('services.frontend_url'), '/').'/shopee-terhubung';
        try {
            $shop = $this->completeAuth((string) $request->query('state'), (int) $request->query('shop_id'), (string) $request->query('code'));
            $user = $shop->user;
            ActivityLog::record($user, 'marketplace.connect', "Menghubungkan toko Shopee {$shop->shop_name}");
            self::syncInBackground($shop);

            return redirect($done.'?'.http_build_query(['id' => $shop->id, 'shop' => $shop->shop_name, 'store' => $user->store_name]));
        } catch (\Throwable $e) {
            report($e);

            return redirect($done.'?error='.urlencode($e instanceof ShopeeException ? $e->getMessage() : 'Gagal menghubungkan toko Shopee. Ulangi dari Pesanin.'));
        }
    }

    /**
     * Local/dev fallback when the redirect URL can't be reached (no public tunnel): the seller pastes
     * the address Shopee sent them to; it carries code, shop_id and our signed state.
     */
    public function completeFromUrl(Request $request)
    {
        $url = (string) $request->validate(['url' => 'required|string|max:2000'])['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        abort_unless(! empty($q['code']) && ! empty($q['shop_id']) && ! empty($q['state']), 422, 'URL tidak berisi code, shop_id dan state dari Shopee. Salin alamat lengkap dari address bar setelah login.');
        try {
            $shop = $this->completeAuth($q['state'], (int) $q['shop_id'], $q['code'], $request->user()->id);
        } catch (ShopeeException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            abort(422, 'Kode otorisasi tidak valid atau sudah kedaluwarsa (berlaku 10 menit). Ulangi Hubungkan toko.');
        }
        ActivityLog::record($request->user(), 'marketplace.connect', "Menghubungkan toko Shopee {$shop->shop_name}");
        self::syncInBackground($shop);

        return ['shop' => $shop->only(['id', 'shop_id', 'shop_name', 'status'])];
    }

    /** Shopee return / refund requests (Pengembalian Barang/Dana), newest first. */
    public function returns(Request $request)
    {
        $data = $request->validate(['shop_id' => 'nullable|integer', 'filter' => 'nullable|in:open,all', 'search' => 'nullable|string|max:60', 'per_page' => 'nullable|integer|min:1|max:1000']);
        $q = \App\Models\ShopeeReturn::where('user_id', $request->user()->id)->with('marketplaceShop:id,shop_name')
            ->when($data['shop_id'] ?? null, fn ($b, $id) => $b->where('marketplace_shop_id', $id))
            ->when(($data['filter'] ?? 'all') === 'open', fn ($b) => $b->whereIn('status', \App\Models\ShopeeReturn::NEEDS_SELLER))
            ->when($data['search'] ?? null, fn ($b, $s) => $b->where(fn ($w) => $w->where('return_sn', 'like', "%{$s}%")->orWhere('order_sn', 'like', "%{$s}%")
                ->orWhere('buyer_username', 'like', "%{$s}%")->orWhere('tracking_number', 'like', "%{$s}%")));

        return $q->orderByRaw('CASE WHEN status IN (\'REQUESTED\', \'PROCESSING\') THEN 0 ELSE 1 END')->latest('requested_at')
            ->paginate($data['per_page'] ?? 10);
    }

    /** First full sync (products, orders, ads) runs after the response is sent, so the redirect is instant and nothing times out. */
    private static function syncInBackground(MarketplaceShop $shop): void
    {
        if ($shop->is_demo) {
            return;
        }
        // A detached `php artisan` process, so the request returns at once however PHP is served
        // (php-fpm, `php artisan serve`, octane…). "After response" work isn't detached under every server
        // and made the Shopee redirect wait for the whole sync (504 at the proxy).
        $php = (new \Symfony\Component\Process\PhpExecutableFinder)->find(false);
        if ($php && function_exists('exec') && ! app()->runningUnitTests()) {
            $cmd = sprintf('nohup %s %s marketplace:initial-sync %d >> %s 2>&1 &',
                escapeshellarg($php), escapeshellarg(base_path('artisan')), $shop->id, escapeshellarg(storage_path('logs/initial-sync.log')));
            @exec($cmd);

            return;
        }
        $id = $shop->id;
        dispatch(function () use ($id) {
            ignore_user_abort(true);
            if ($s = MarketplaceShop::find($id)) {
                (new ShopeeSync)->initial($s);
            }
        })->afterResponse();
    }

    /** Exchange Shopee's one-time code for tokens and save the shop for the user in the signed state. */
    private function completeAuth(string $stateToken, int $shopId, string $code, ?int $mustBeUser = null): MarketplaceShop
    {
        $state = json_decode(Crypt::decryptString($stateToken), true);
        abort_if(! $state || $state['exp'] < time() || ($mustBeUser && $state['uid'] !== $mustBeUser), 400);
        $user = User::findOrFail($state['uid']);
        abort_unless($shopId && $code, 400);

        $t = (new ShopeeClient)->getToken($code, $shopId);
        $shop = MarketplaceShop::firstOrNew(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => $shopId]);
        $shop->fill([
            'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'],
            'access_expires_at' => now()->addSeconds($t['expire_in'] ?? 14400), 'refresh_expires_at' => now()->addDays(30),
            'status' => 'active', 'last_error' => null, 'shop_name' => $shop->shop_name ?? "Toko {$shopId}",
        ])->save();
        // Make the first visit useful immediately: import the shop's existing
        // catalogue and recent orders before redirecting the seller back.
        // A sync failure must not invalidate an otherwise successful OAuth flow;
        // the scheduled/manual sync can retry it later.
        $sync = new ShopeeSync;
        $syncErrors = [];
        foreach (['shop' => fn () => $sync->shopInfo($shop), 'products' => fn () => $sync->products($shop), 'orders' => fn () => $sync->orders($shop)] as $part => $run) {
            try {
                $run();
            } catch (ShopeeException $e) {
                $syncErrors[] = "{$part}: {$e->getMessage()}";
            }
        }
        $shop->update(['last_error' => $syncErrors ? mb_substr(implode(' | ', $syncErrors), 0, 250) : null]);

        return $shop->setRelation('user', $user);
    }

    /** Iklan Shopee: spend & results per campaign and per promoted product for a date range. */
    public function ads(Request $request)
    {
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'shop' => 'nullable|integer']);
        $from = \Illuminate\Support\Carbon::parse($data['from'] ?? now()->subDays(29))->toDateString();
        $to = \Illuminate\Support\Carbon::parse($data['to'] ?? now())->toDateString();
        $u = $request->user();
        $shopIds = $u->marketplaceShops()->when($data['shop'] ?? null, fn ($q, $id) => $q->whereKey($id))->pluck('id');

        $campaigns = \App\Models\ShopeeAdCampaign::whereIn('marketplace_shop_id', $shopIds)
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'expense')
            ->withSum(['stats as gmv' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'gmv')
            ->withSum(['stats as clicks' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'clicks')
            ->withSum(['stats as impressions' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'impressions')
            ->withSum(['stats as orders' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'orders')
            ->withSum(['stats as items_sold' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'items_sold')
            ->get();
        $listings = MarketplaceListing::whereIn('marketplace_shop_id', $shopIds)->whereIn('item_id', $campaigns->pluck('item_ids')->flatten()->unique())
            ->get()->groupBy('item_id')->map(fn ($g) => ['item_id' => (int) $g->first()->item_id, 'name' => $g->first()->name, 'image' => $g->first()->image, 'product_id' => $g->firstWhere('product_id')?->product_id]);

        $row = fn ($c) => [
            'expense' => (int) $c->expense, 'gmv' => (int) $c->gmv, 'clicks' => (int) $c->clicks, 'impressions' => (int) $c->impressions,
            'orders' => (int) $c->orders, 'items_sold' => (int) $c->items_sold,
        ];
        $campaignRows = $campaigns->map(fn ($c) => ['id' => $c->id, 'campaign_id' => $c->campaign_id, 'name' => $c->name ?: "Kampanye {$c->campaign_id}", 'status' => $c->status, 'ad_type' => $c->ad_type, 'bidding_method' => $c->bidding_method, 'budget' => $c->budget,
            'items' => collect($c->item_ids ?? [])->map(fn ($id) => $listings[$id] ?? ['item_id' => $id, 'name' => "Produk {$id}", 'image' => null, 'product_id' => null])->values()] + $row($c))
            ->sortByDesc('expense')->values();

        // Per product: a campaign promoting several products is split evenly between them.
        $products = $campaignRows->flatMap(fn ($c) => collect($c['items'])->map(fn ($it) => ['item' => $it, 'share' => 1 / max(1, count($c['items'])), 'c' => $c]))
            ->groupBy(fn ($x) => $x['item']['item_id'])->map(function ($g) {
                $sum = fn ($k) => (int) round($g->sum(fn ($x) => $x['c'][$k] * $x['share']));

                return $g->first()['item'] + ['campaigns' => $g->count(), 'shared' => $g->contains(fn ($x) => $x['share'] < 1),
                    'expense' => $sum('expense'), 'gmv' => $sum('gmv'), 'clicks' => $sum('clicks'), 'orders' => $sum('orders')];
            })->sortByDesc('expense')->values();

        $daily = \App\Models\AdSpend::where('user_id', $u->id)->where('platform', 'shopee_ads')->where('synced', true)
            ->whereIn('marketplace_shop_id', $shopIds)->whereBetween('spent_on', [$from, $to]);

        return [
            'from' => $from, 'to' => $to,
            'total' => ['expense' => (int) (clone $daily)->sum('amount')] + collect(['gmv', 'clicks', 'impressions', 'orders'])->mapWithKeys(fn ($k) => [$k => $campaignRows->sum($k)])->all(),
            'daily' => (clone $daily)->selectRaw('spent_on as date, SUM(amount) as expense')->groupBy('spent_on')->orderBy('spent_on')->get(),
            'campaigns' => $campaignRows, 'products' => $products,
            // Sandbox shops are topped up via Shopee Console → Test Account → Login Seller Center.
            'topup_url' => config('services.shopee.host') === 'https://partner.shopeemobile.com' ? 'https://seller.shopee.co.id/portal/marketing/pas/index' : null,
            'balance' => $this->adsBalance($u, $shopIds),
            'synced_at' => $u->marketplaceShops()->whereIn('id', $shopIds)->max('updated_at'),
        ];
    }

    /**
     * Saldo iklan for the selected shop(s), read live from v2.ads.get_total_balance
     * (cached 60s per shop so reloads don't hammer Shopee). Falls back to the last stored value on error.
     */
    private function adsBalance(User $u, $shopIds): array
    {
        $shops = $u->marketplaceShops()->whereIn('id', $shopIds)->where('is_demo', false)->where('status', 'active')->get();
        $api = new ShopeeClient;
        $rows = $shops->map(function (MarketplaceShop $shop) use ($api) {
            $live = \Illuminate\Support\Facades\Cache::remember("shopee:ads_balance:{$shop->id}", 60, function () use ($api, $shop) {
                try {
                    $amount = $api->adsBalance($shop);
                    $shop->update(['ads_balance' => $amount, 'ads_balance_at' => now()]);

                    return true;
                } catch (\Throwable $e) {
                    report($e);

                    return false;
                }
            });
            $shop->refresh();

            return ['amount' => $shop->ads_balance, 'at' => $shop->ads_balance_at, 'live' => $live];
        });

        return [
            'amount' => $rows->whereNotNull('amount')->sum('amount'),
            'shops' => $rows->count(),
            'checked_at' => $rows->pluck('at')->filter()->min(),
            'stale' => $rows->contains(fn ($r) => ! $r['live']),
        ];
    }

    public function sync(Request $request, MarketplaceShop $shop)
    {
        $this->own($request, $shop);
        $what = $request->validate(['what' => 'nullable|in:all,products,orders,ads'])['what'] ?? 'all';
        if ($shop->is_demo) {
            return ['message' => 'Toko contoh tidak perlu disinkronkan.', 'result' => []];
        }
        // A full pull can take minutes on a busy shop; keep going even if the browser or proxy gives up waiting.
        ignore_user_abort(true);
        @set_time_limit(0);
        $sync = new ShopeeSync;
        $result = [];
        try {
            if (in_array($what, ['all', 'products'])) {
                $sync->shopInfo($shop);
                $result['products'] = $sync->products($shop);
            }
            if (in_array($what, ['all', 'orders'])) {
                $result['orders'] = $sync->orders($shop);
            }
            if (in_array($what, ['all', 'ads'])) {
                try {
                    $result['ads'] = $sync->ads($shop);
                } catch (ShopeeException $e) {
                    $result['ads_error'] = 'Biaya Shopee Ads belum bisa diambil: '.$e->getMessage();
                }
            }
        } catch (ShopeeException $e) {
            $shop->update(['last_error' => $e->getMessage()]);
            abort(422, 'Sinkronisasi gagal: '.$e->getMessage());
        } catch (\Throwable $e) {
            // Unexpected (database, timeout…): keep what was synced so far, log it, and tell the seller plainly.
            report($e);
            $shop->update(['last_error' => 'Sinkronisasi terhenti: '.mb_substr($e->getMessage(), 0, 200)]);
            abort(422, 'Sinkronisasi terhenti karena kesalahan di server Pesanin. Data yang sudah masuk tetap tersimpan; coba Sinkronkan lagi. Kalau berulang, hubungi tim Pesanin.');
        }

        return ['message' => 'Sinkronisasi selesai', 'result' => $result, 'shop' => $shop->fresh()];
    }

    /** Transaction rows of a shop for the request's date range, status and search filters. */
    private function transactionRows(Request $request, MarketplaceShop $shop): array
    {
        $this->own($request, $shop);
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date', 'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:30', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:1000',
        ]);
        $to = Carbon::parse($data['to'] ?? today())->endOfDay();
        $from = Carbon::parse($data['from'] ?? $to->copy()->subDays(29))->startOfDay();
        $rows = Analytics::transactions($request->user(), $from, $to, $shop->id)
            ->when($data['status'] ?? null, fn ($c, $status) => $c->where('status', $status))
            ->when($data['search'] ?? null, function ($c, $search) {
                $needle = mb_strtolower($search);
                return $c->filter(fn ($row) => str_contains(mb_strtolower(($row['order_sn'] ?? '').' '.($row['customer_name'] ?? '').' '.($row['tracking_number'] ?? '')), $needle));
            })->values();

        return [$rows, $data, $from, $to];
    }

    /**
     * Excel-friendly CSV (semicolon, UTF-8 BOM) of the shop's transactions with one column per Shopee fee line,
     * like Finkami's Ekspor.
     */
    public function exportTransactions(Request $request, MarketplaceShop $shop)
    {
        [$rows, , $from, $to] = $this->transactionRows($request, $shop);
        $feeCols = $rows->flatMap(fn ($r) => collect($r['fee_breakdown'])->pluck('label'))->unique()->values()->all();
        $head = ['No Pesanan', 'Tanggal', 'Status', 'Produk', 'Variasi', 'Qty', 'Jumlah (harga produk)', ...$feeCols,
            'Total Biaya', 'Pendapatan Bersih', 'HPP', 'Laba', 'Margin %', 'Penghasilan', 'Resi', 'Kurir'];
        $statusLabel = ['UNPAID' => 'Belum bayar', 'READY_TO_SHIP' => 'Perlu dikirim', 'PROCESSED' => 'Siap diserahkan', 'RETRY_SHIP' => 'Kirim ulang',
            'SHIPPED' => 'Dikirim', 'TO_CONFIRM_RECEIVE' => 'Menunggu konfirmasi', 'COMPLETED' => 'Selesai', 'IN_CANCEL' => 'Pembatalan', 'CANCELLED' => 'Dibatalkan', 'TO_RETURN' => 'Retur'];
        $name = 'transaksi-'.Str::slug($shop->shop_name).'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows, $head, $feeCols, $statusLabel) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head, ';');
            foreach ($rows as $r) {
                $fees = collect($r['fee_breakdown'])->groupBy('label')->map(fn ($g) => $g->sum('amount'));
                fputcsv($out, [
                    $r['order_sn'], $r['created_at']->format('Y-m-d H:i'), $statusLabel[$r['status']] ?? $r['status'],
                    collect($r['items'])->pluck('name')->implode(' | '), collect($r['items'])->pluck('variant')->filter()->implode(' | '),
                    $r['quantity'], $r['revenue'], ...array_map(fn ($c) => $fees[$c] ?? 0, $feeCols),
                    -$r['fees'], $r['net_revenue'], $r['cogs'], $r['profit'], $r['margin'],
                    $r['fee_final'] ? 'Sudah cair' : 'Estimasi', $r['tracking_number'], $r['courier'],
                ], ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function transactions(Request $request, MarketplaceShop $shop)
    {
        [$rows, $data, $from] = $this->transactionRows($request, $shop);
        $backfilling = \App\Support\Shopee\ShopeeBackfill::ensureShop($shop, $from);
        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);

        return [
            'transactions' => [
                'data' => $rows->forPage($page, $perPage)->values(), 'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'total' => $rows->count(),
            ],
            'summary' => [
                'gmv' => (int) $rows->sum('gmv'), 'revenue' => (int) $rows->sum('revenue'), 'fees' => (int) $rows->sum('fees'),
                'net_revenue' => (int) $rows->sum('net_revenue'), 'cogs' => (int) $rows->sum('cogs'),
                'profit' => (int) $rows->sum('profit'), 'missing_cost' => $rows->where('missing_cost', true)->count(),
            ],
            'backfilling' => $backfilling,
        ];
    }

    public function disconnect(Request $request, MarketplaceShop $shop)
    {
        $this->own($request, $shop);
        if ($shop->is_demo) {
            $shop->orders()->delete();
            $request->user()->adSpends()->where('marketplace_shop_id', $shop->id)->delete();
            $shop->delete();

            return response()->noContent();
        }
        // Keep order history for reports; drop credentials and listings.
        $shop->listings()->delete();
        $shop->update(['status' => 'disconnected', 'access_token' => null, 'refresh_token' => null]);
        ActivityLog::record($request->user(), 'marketplace.disconnect', "Memutus toko Shopee {$shop->shop_name}");

        return response()->noContent();
    }

    public function demo(Request $request)
    {
        abort_unless(app()->isLocal(), 404);

        return response()->json(ShopeeDemo::seed($request->user()), 201);
    }

    public function listings(Request $request)
    {
        $q = MarketplaceListing::where('user_id', $request->user()->id)
            ->with(['shop:id,shop_name,shop_id,is_demo', 'product:id,name,sku,cost_price,stock,track_stock'])
            ->when($request->query('shop_id'), fn ($qq, $v) => $qq->where('marketplace_shop_id', $v))
            ->when($request->query('status'), fn ($qq, $v) => $qq->where('status', $v))
            ->when($request->query('cost') === 'filled', fn ($qq) => $qq->where('cost_price', '>', 0))
            ->when($request->query('cost') === 'empty', fn ($qq) => $qq->where(fn ($w) => $w->whereNull('cost_price')->orWhere('cost_price', 0)))
            ->when($request->query('search'), fn ($qq, $s) => $qq->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%")))
            ->orderBy('name')->orderBy('model_name');

        $base = MarketplaceListing::where('user_id', $request->user()->id);

        // Paginate by Shopee product (shop + item), not by variation, so a product's variations stay together.
        $groups = (clone $q)->reorder()->select('marketplace_shop_id', 'item_id')->selectRaw('MIN(name) as group_name')
            ->groupBy('marketplace_shop_id', 'item_id')->orderBy('group_name')->orderBy('marketplace_shop_id')
            ->paginate(min(1000, max(1, $request->integer('per_page', 10))));
        $rows = $groups->isEmpty() ? collect() : (clone $q)->where(fn ($w) => $groups->each(
            fn ($g) => $w->orWhere(fn ($x) => $x->where('marketplace_shop_id', $g->marketplace_shop_id)->where('item_id', $g->item_id))
        ))->get();
        $listings = $groups->setCollection($rows);

        return [
            'listings' => $listings,
            'stats' => [
                'total' => (clone $base)->count(), 'linked' => (clone $base)->whereNotNull('product_id')->count(),
                'out_of_stock' => (clone $base)->where('stock', '<=', 0)->count(), 'stock' => (int) (clone $base)->sum('stock'),
                'with_cost' => (clone $base)->whereNotNull('cost_price')->where('cost_price', '>', 0)->count(),
            ],
        ];
    }

    public function link(Request $request, MarketplaceListing $listing)
    {
        abort_unless($listing->user_id === $request->user()->id, 404);
        $data = $request->validate(['product_id' => ['nullable', Rule::exists('products', 'id')->where('user_id', $request->user()->id)]]);
        $listing->update(['product_id' => $data['product_id'] ?? null]);
        // Re-attribute past Shopee orders of this item so HPP and reports pick up the link.
        $request->user()->orders()->where('marketplace_shop_id', $listing->marketplace_shop_id)->whereNotNull('items')->each(function ($o) use ($listing) {
            $items = collect($o->items)->map(fn ($i) => $i['item_id'] == $listing->item_id && ($i['model_id'] ?? 0) == $listing->model_id ? ['product_id' => $listing->product_id] + $i : $i);
            $o->update(['items' => $items->all(), 'product_id' => $items->firstWhere('product_id')['product_id'] ?? null]);
        });

        return $listing->load('product:id,name,sku,cost_price');
    }

    public function cost(Request $request, MarketplaceListing $listing)
    {
        abort_unless($listing->user_id === $request->user()->id, 404);
        $data = $request->validate(['cost_price' => 'nullable|integer|min:0|max:1000000000']);
        $listing->update(['cost_price' => $data['cost_price'] ?? null]);

        return $listing->only(['id', 'cost_price']);
    }

    /**
     * Groups for bulk HPP entry: one row per unique SKU, or per unique product name (+ variation),
     * with how many listings (across all shops) share it and the HPP they currently have.
     */
    public function costGroups(Request $request)
    {
        $by = $request->validate(['by' => 'required|in:sku,name'])['by'];
        $listings = MarketplaceListing::where('user_id', $request->user()->id)->with('shop:id,shop_name')->get();
        $key = fn ($l) => $by === 'sku' ? ($l->sku ?: null) : trim($l->name.($l->model_name ? ' · '.$l->model_name : ''));

        $groups = $listings->groupBy(fn ($l) => $key($l) ?? '')->map(function ($g, $k) {
            $costs = $g->pluck('cost_price')->filter()->unique()->values();

            return [
                'key' => $k, 'listings' => $g->count(), 'shops' => $g->pluck('shop.shop_name')->unique()->values(),
                'price' => (int) $g->min('price'), 'image' => $g->first()->image,
                'example' => trim($g->first()->name.($g->first()->model_name ? ' · '.$g->first()->model_name : '')),
                'cost_price' => $costs->count() === 1 ? (int) $costs[0] : null, 'mixed' => $costs->count() > 1,
            ];
        });

        return [
            'by' => $by,
            'groups' => $groups->except([''])->sortBy('key')->values(),
            'without_key' => $groups[''] ['listings'] ?? 0,
        ];
    }

    /** Apply HPP to every listing that shares a SKU or product name. */
    public function bulkCost(Request $request)
    {
        $data = $request->validate([
            'by' => 'required|in:sku,name',
            'items' => 'required|array|min:1|max:500',
            'items.*.key' => 'required|string|max:255',
            'items.*.cost_price' => 'nullable|integer|min:0|max:1000000000',
        ]);
        $listings = MarketplaceListing::where('user_id', $request->user()->id)->get();
        $updated = 0;
        foreach ($data['items'] as $item) {
            $match = $listings->filter(fn ($l) => $data['by'] === 'sku'
                ? $l->sku === $item['key']
                : trim($l->name.($l->model_name ? ' · '.$l->model_name : '')) === $item['key']);
            foreach ($match as $l) {
                $l->update(['cost_price' => $item['cost_price'] ?: null]);
                $updated++;
            }
        }

        return ['updated' => $updated];
    }

    public function stock(Request $request, MarketplaceListing $listing)
    {
        abort_unless($listing->user_id === $request->user()->id, 404);
        $data = $request->validate(['stock' => 'required|integer|min:0|max:999999']);
        $shop = $listing->shop;
        if (! $shop->is_demo) {
            try {
                (new ShopeeClient)->updateStock($shop, $listing->item_id, $listing->model_id, $data['stock']);
            } catch (ShopeeException $e) {
                abort(422, 'Shopee menolak update stok: '.$e->getMessage());
            }
        }
        $listing->update(['stock' => $data['stock']]);

        return $listing;
    }

    private function own(Request $request, MarketplaceShop $shop): void
    {
        abort_unless($shop->user_id === $request->user()->id, 404);
    }
}
