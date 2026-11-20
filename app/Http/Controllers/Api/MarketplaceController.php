<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
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
            ->withCount([
                'listings',
                'orders',
                'orders as orders_today_count' => fn ($q) => $q
                    ->where('is_abandoned', false)
                    ->where('status', '!=', 'cancelled')
                    ->whereNotIn('external_status', ['CANCELLED', 'IN_CANCEL', 'UNPAID'])
                    ->where(fn ($p) => $p->where('payment_status', 'paid')->orWhere('payment_method', 'cod'))
                    ->whereBetween('created_at', \App\Support\Dates::today()),
            ])
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
    /**
     * Marketplace → Saldo: each Shopee shop's seller balance from Shopee's payment API, plus the totals across shops.
     * A total is null when no shop returned that figure. ?fresh=1 skips the short cache.
     */
    public function balance(Request $request)
    {
        $wallets = $request->user()->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->get()
            ->map(fn ($shop) => \App\Support\Shopee\ShopeeWallet::summary($shop, $request->boolean('fresh')))->values();
        $total = function (string $key) use ($wallets) {
            $values = $wallets->pluck($key)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : (int) $values->sum();
        };

        return [
            'shops' => $wallets,
            'totals' => ['balance' => $total('balance'), 'pending' => $total('pending'), 'released' => $total('released')],
        ];
    }

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
        $data = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'shop' => 'nullable|integer', 'all' => 'nullable|boolean']);
        $today = now(\App\Support\Dates::TZ)->toDateString();
        $from = \Illuminate\Support\Carbon::parse($data['from'] ?? $today)->toDateString();
        $to = \Illuminate\Support\Carbon::parse($data['to'] ?? $today)->toDateString();
        // The lists show running ads only unless ?all=1; totals always cover every ad, like Seller Centre.
        $activeOnly = ! $request->boolean('all');
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
        $shopNormalListings = MarketplaceListing::whereIn('marketplace_shop_id', $shopIds)->where('status', 'NORMAL')
            ->get()->groupBy('marketplace_shop_id')
            ->map(fn ($g) => $g->groupBy('item_id')->map(fn ($ig) => [
                'item_id' => (int) $ig->first()->item_id,
                'name' => $ig->first()->name,
                'image' => $ig->first()->image,
                'product_id' => $ig->firstWhere('product_id')?->product_id,
            ])->values()->all());

        $row = fn ($c) => [
            'expense' => (int) $c->expense, 'gmv' => (int) $c->gmv, 'clicks' => (int) $c->clicks, 'impressions' => (int) $c->impressions,
            'orders' => (int) $c->orders, 'items_sold' => (int) $c->items_sold,
        ];
        $campaignRows = $campaigns->map(function ($c) use ($listings, $shopNormalListings, $row) {
            $explicitItems = collect($c->item_ids ?? [])->map(fn ($id) => $listings[$id] ?? ['item_id' => $id, 'name' => "Produk {$id}", 'image' => null, 'product_id' => null])->values();
            $nameLower = mb_strtolower((string) ($c->name ?? ''));
            // Store-wide automated product ads have no specific item list
            $isStoreWideAuto = ($c->ad_type === 'auto' || $c->ad_type === 'shop_auto') && $explicitItems->isEmpty();
            $isGroup = ! $isStoreWideAuto && ($c->ad_type === 'group' || $explicitItems->count() > 1 || str_contains($nameLower, 'grup') || str_contains($nameLower, 'group'));
            $kind = $isStoreWideAuto ? 'auto' : ($isGroup ? 'group' : 'manual');
            $autoItems = $isStoreWideAuto ? collect($shopNormalListings[$c->marketplace_shop_id] ?? []) : collect();

            return [
                'id' => $c->id,
                'marketplace_shop_id' => $c->marketplace_shop_id,
                'campaign_id' => $c->campaign_id,
                'name' => $c->name ?: ($isStoreWideAuto ? 'Iklan Produk Otomatis' : ($isGroup ? "Grup Iklan #{$c->campaign_id}" : "Kampanye {$c->campaign_id}")),
                'status' => $c->status ?: 'ongoing',
                'ad_type' => $c->ad_type ?: ($isStoreWideAuto ? 'auto' : 'manual'),
                'kind' => $kind,
                'bidding_method' => $c->bidding_method,
                'budget' => $c->budget,
                'roas_target' => $c->roas_target ? (float) $c->roas_target : null,
                'items' => $explicitItems,
                'auto_items' => $autoItems->values(),
                'auto_product_count' => $isStoreWideAuto ? $autoItems->count() : $explicitItems->count(),
            ] + $row($c);
        })->sortByDesc('expense')->values();
        $allCampaignRows = $campaignRows;
        if ($activeOnly) {
            $campaignRows = $campaignRows->where('status', 'ongoing')->values();
        }

        // Per product: a campaign promoting several products is split evenly between them.
        $products = $campaignRows->flatMap(fn ($c) => collect($c['items'])->map(fn ($it) => ['item' => $it, 'share' => 1 / max(1, count($c['items'])), 'c' => $c]))
            ->groupBy(fn ($x) => $x['item']['item_id'])->map(function ($g) {
                $sum = fn ($k) => (int) round($g->sum(fn ($x) => $x['c'][$k] * $x['share']));
                $firstCamp = $g->first()['c'];
                $budgets = $g->pluck('c.budget')->filter(fn ($b) => $b !== null && $b > 0);

                return $g->first()['item'] + [
                    'campaigns' => $g->count(),
                    'shared' => $g->contains(fn ($x) => $x['share'] < 1),
                    'status' => $g->contains(fn ($x) => $x['c']['status'] === 'ongoing') ? 'ongoing' : ($firstCamp['status'] ?? null),
                    'ad_type' => $firstCamp['ad_type'] ?? null,
                    'bidding_method' => $firstCamp['bidding_method'] ?? null,
                    'budget' => $budgets->isEmpty() ? null : (int) $budgets->sum(),
                    'expense' => $sum('expense'),
                    'gmv' => $sum('gmv'),
                    'clicks' => $sum('clicks'),
                    'impressions' => $sum('impressions'),
                    'orders' => $sum('orders'),
                    'items_sold' => $sum('items_sold'),
                ];
            })->sortByDesc('expense')->values();

        $daily = \App\Models\AdSpend::where('user_id', $u->id)->where('platform', 'shopee_ads')->where('synced', true)
            ->whereIn('marketplace_shop_id', $shopIds)->whereBetween('spent_on', [$from, $to]);
        $dailyStats = \App\Models\ShopeeAdStat::whereIn('shopee_ad_campaign_id', $campaigns->pluck('id'))
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(expense) as stat_expense, SUM(gmv) as gmv, SUM(orders) as orders, SUM(items_sold) as items_sold')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($r) => \Illuminate\Support\Carbon::parse($r->date)->toDateString());

        $dailyRows = (clone $daily)->selectRaw('spent_on as date, SUM(amount) as expense')->groupBy('spent_on')->orderBy('spent_on')->get()
            ->map(function ($d) use ($dailyStats) {
                $dateStr = \Illuminate\Support\Carbon::parse($d->date)->toDateString();
                $st = $dailyStats[$dateStr] ?? null;

                return [
                    'date' => $dateStr,
                    'expense' => (int) $d->expense,
                    'impressions' => (int) ($st->impressions ?? 0),
                    'clicks' => (int) ($st->clicks ?? 0),
                    'gmv' => (int) ($st->gmv ?? 0),
                    'orders' => (int) ($st->orders ?? 0),
                    'items_sold' => (int) ($st->items_sold ?? 0),
                ];
            });

        return [
            'from' => $from, 'to' => $to,
            'total' => ['expense' => (int) (clone $daily)->sum('amount')] + collect(['gmv', 'clicks', 'impressions', 'orders', 'items_sold'])->mapWithKeys(fn ($k) => [$k => $allCampaignRows->sum($k)])->all(),
            'active_only' => $activeOnly,
            'counts' => ['campaigns' => $allCampaignRows->count(), 'active' => $allCampaignRows->where('status', 'ongoing')->count()],
            'status_counts' => [
                'all' => $allCampaignRows->count(),
                'ongoing' => $allCampaignRows->where('status', 'ongoing')->count(),
                'scheduled' => $allCampaignRows->where('status', 'scheduled')->count(),
                'paused' => $allCampaignRows->where('status', 'paused')->count(),
                'ended' => $allCampaignRows->where('status', 'ended')->count(),
                'closed' => $allCampaignRows->where('status', 'closed')->count(),
            ],
            'kind_counts' => [
                'all' => $allCampaignRows->count(),
                'manual' => $allCampaignRows->where('kind', 'manual')->count(),
                'group' => $allCampaignRows->where('kind', 'group')->count(),
                'auto' => $allCampaignRows->where('kind', 'auto')->count(),
            ],
            'daily' => $dailyRows,
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
        $validated = $request->validate([
            'what' => 'nullable|in:all,products,orders,ads,recent',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $what = $validated['what'] ?? 'all';
        $force = $request->boolean('force');
        if ($shop->is_demo) {
            return ['message' => 'Toko contoh tidak perlu disinkronkan.', 'result' => []];
        }
        // A full pull can take minutes on a busy shop; keep going even if the browser or proxy gives up waiting.
        ignore_user_abort(true);
        @set_time_limit(0);
        $sync = new ShopeeSync;
        $result = [];
        try {
            if ($what === 'recent') {
                $result['recent'] = $sync->recent($shop, 60);
                $sync->settle($shop, 25);
            }
            if (in_array($what, ['all', 'products'])) {
                $sync->shopInfo($shop);
                $result['products'] = $sync->products($shop);
            }
            if (in_array($what, ['all', 'orders'])) {
                if (! empty($validated['from']) || ! empty($validated['to'])) {
                    [$rf, $rt] = \App\Support\Dates::range($validated['from'] ?? null, $validated['to'] ?? null);
                    $doneKey = "shopee:sync_range_done:{$shop->id}:{$rf->toDateString()}:{$rt->toDateString()}";
                    if (! $force && \Illuminate\Support\Facades\Cache::has($doneKey)) {
                        $result['orders'] = 0;
                        $result['settled'] = 0;
                        $result['remaining'] = 0;
                    } else {
                        $rangeRes = $sync->syncRange($shop, $rf, $rt, $force);
                        $result['orders'] = $rangeRes['orders'];
                        $result['settled'] = $rangeRes['settled'];
                        $result['remaining_orders'] = $rangeRes['remaining_orders'];
                        $result['remaining_escrow'] = $rangeRes['remaining_escrow'];
                        $result['remaining'] = $rangeRes['remaining'];
                        $result['shopee_total'] = $rangeRes['shopee_total'];
                        $result['progress_date'] = $rangeRes['progress_date'] ?? null;
                        // Only mark this range as settled for 45s once 100% of windows in the range are scanned.
                        if ($rangeRes['remaining'] === 0) {
                            \Illuminate\Support\Facades\Cache::put($doneKey, 1, 45);
                        }
                    }
                } else {
                    $result['orders'] = $sync->orders($shop);
                }
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

    /** Match a transaction row against one of the UI status tabs. */
    private static function matchTransactionTab(array $row, string $tab): bool
    {
        $st = (string) ($row['status'] ?? '');
        $hasResi = ! empty($row['tracking_number']);

        return match ($tab) {
            'READY_TO_SHIP' => in_array($st, ['READY_TO_SHIP', 'PENDING'], true) || ($st === 'IN_CANCEL' && ! $hasResi),
            'PROCESSED' => in_array($st, ['PROCESSED', 'RETRY_SHIP'], true) || ($st === 'IN_CANCEL' && $hasResi),
            'SHIPPED' => in_array($st, ['SHIPPED', 'TO_CONFIRM_RECEIVE'], true),
            'COMPLETED' => $st === 'COMPLETED',
            'CANCELLED' => in_array($st, ['CANCELLED', 'IN_CANCEL'], true),
            'TO_RETURN' => $st === 'TO_RETURN',
            default => $st === $tab,
        };
    }

    /** Transaction rows of a shop for the request's date range, status and search filters. */
    private function transactionRows(Request $request, MarketplaceShop $shop, bool $withFeeBreakdown = false): array
    {
        $this->own($request, $shop);
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date', 'search' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:30', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:10|max:25000',
        ]);
        [$from, $to] = \App\Support\Dates::range($data['from'] ?? null, $data['to'] ?? null);
        $baseRows = Analytics::transactions($request->user(), $from, $to, $shop->id, $withFeeBreakdown)->values();

        $needle = ! empty($data['search']) ? mb_strtolower($data['search']) : null;
        $statusFilter = $data['status'] ?? null;

        $tabCounts = [
            '' => 0,
            'READY_TO_SHIP' => 0,
            'PROCESSED' => 0,
            'SHIPPED' => 0,
            'COMPLETED' => 0,
            'CANCELLED' => 0,
            'TO_RETURN' => 0,
        ];
        $filtered = [];

        foreach ($baseRows as $r) {
            if ($needle !== null) {
                $itemsText = collect($r['items'] ?? [])->map(fn ($i) => ($i['name'] ?? '').' '.($i['variant'] ?? '').' '.($i['sku'] ?? ''))->implode(' ');
                if (! str_contains(mb_strtolower(($r['order_sn'] ?? '').' '.($r['customer_name'] ?? '').' '.($r['tracking_number'] ?? '').' '.$itemsText), $needle)) {
                    continue;
                }
            }
            $tabCounts['']++;
            $st = (string) ($r['status'] ?? '');
            $hasResi = ! empty($r['tracking_number']);
            if ($st === 'READY_TO_SHIP' || $st === 'PENDING' || ($st === 'IN_CANCEL' && ! $hasResi)) {
                $tabCounts['READY_TO_SHIP']++;
            }
            if ($st === 'PROCESSED' || $st === 'RETRY_SHIP' || ($st === 'IN_CANCEL' && $hasResi)) {
                $tabCounts['PROCESSED']++;
            }
            if ($st === 'SHIPPED' || $st === 'TO_CONFIRM_RECEIVE') {
                $tabCounts['SHIPPED']++;
            }
            if ($st === 'COMPLETED') {
                $tabCounts['COMPLETED']++;
            }
            if ($st === 'CANCELLED' || $st === 'IN_CANCEL') {
                $tabCounts['CANCELLED']++;
            }
            if ($st === 'TO_RETURN') {
                $tabCounts['TO_RETURN']++;
            }

            if (! $statusFilter || self::matchTransactionTab($r, $statusFilter)) {
                $filtered[] = $r;
            }
        }

        $rows = collect($filtered);

        return [$rows, $data, $from, $to, $tabCounts, $baseRows];
    }

    /**
     * Excel-friendly CSV (semicolon, UTF-8 BOM) of the shop's transactions with one column per Shopee fee line,
     * like Finkami's Ekspor.
     */
    public function exportTransactions(Request $request, MarketplaceShop $shop)
    {
        [$rows, , $from, $to] = $this->transactionRows($request, $shop, true);
        $feeCols = $rows->flatMap(fn ($r) => collect($r['fee_breakdown'])->pluck('label'))->unique()->values()->all();
        $head = ['No Pesanan', 'Tanggal', 'Status', 'Produk', 'Variasi', 'Qty', 'Jumlah (harga produk)', ...$feeCols,
            'Total Biaya', 'Pendapatan Bersih', 'HPP', 'Biaya Iklan (Konversi)', 'Laba', 'Margin %', 'Penghasilan', 'Resi', 'Kurir'];
        $statusLabel = ['UNPAID' => 'Belum bayar', 'PENDING' => 'Tertunda', 'READY_TO_SHIP' => 'Perlu dikirim', 'PROCESSED' => 'Siap diserahkan', 'RETRY_SHIP' => 'Kirim ulang',
            'SHIPPED' => 'Dikirim', 'TO_CONFIRM_RECEIVE' => 'Menunggu konfirmasi', 'COMPLETED' => 'Selesai', 'IN_CANCEL' => 'Pembatalan', 'CANCELLED' => 'Dibatalkan', 'TO_RETURN' => 'Retur'];
        $name = 'transaksi-'.Str::slug($shop->shop_name).'-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows, $head, $feeCols, $statusLabel) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head, ';');
            foreach ($rows as $r) {
                $fees = collect($r['fee_breakdown'])->groupBy('label')->map(fn ($g) => $g->sum('amount'));
                $dateVal = ($r['status'] === 'COMPLETED' && ! empty($r['completed_at'])) ? $r['completed_at'] : $r['created_at'];
                $dateStr = $dateVal instanceof \DateTimeInterface
                    ? $dateVal->format('Y-m-d H:i')
                    : substr((string) $dateVal, 0, 16);
                fputcsv($out, [
                    $r['order_sn'], $dateStr, $statusLabel[$r['status']] ?? $r['status'],
                    collect($r['items'])->pluck('name')->implode(' | '), collect($r['items'])->pluck('variant')->filter()->implode(' | '),
                    $r['quantity'], $r['revenue'], ...array_map(fn ($c) => $fees[$c] ?? 0, $feeCols),
                    -$r['fees'], $r['net_revenue'], $r['cogs'], -($r['ad_cost'] ?? 0), $r['profit'], $r['margin'],
                    $r['fee_final'] ? 'Sudah cair' : 'Estimasi', $r['tracking_number'], $r['courier'],
                ], ';');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function transactions(Request $request, MarketplaceShop $shop)
    {
        $allRowsMode = $request->boolean('all_rows');
        [$rows, $data, $from, , $tabCounts, $baseRows] = $this->transactionRows($request, $shop, false);
        $backfilling = ! $allRowsMode && \App\Support\Shopee\ShopeeBackfill::ensureShop($shop, $from);
        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $pageRows = $allRowsMode
            ? $rows->forPage($page, min($perPage, 50))->values()
            : Analytics::attachFeeBreakdowns($rows->forPage($page, $perPage)->values());

        $gmv = 0;
        $revenue = 0;
        $fees = 0;
        $netRevenue = 0;
        $cogs = 0;
        $ads = 0;
        $grossProfit = 0;
        $profit = 0;
        $missingCost = 0;
        foreach ($rows as $r) {
            $gmv += $r['gmv'];
            $revenue += $r['revenue'];
            $fees += $r['fees'];
            $netRevenue += $r['net_revenue'];
            $cogs += $r['cogs'];
            $ads += $r['ad_cost'];
            $grossProfit += $r['gross_profit'];
            $profit += $r['profit'];
            if ($r['missing_cost']) {
                $missingCost++;
            }
        }

        return [
            'rows' => $allRowsMode ? ($baseRows->count() <= 300 ? Analytics::attachFeeBreakdowns($baseRows) : $baseRows) : null,
            'transactions' => [
                'data' => $pageRows, 'current_page' => $page,
                'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'total' => $rows->count(),
            ],
            'summary' => [
                'gmv' => $gmv, 'revenue' => $revenue, 'fees' => $fees,
                'net_revenue' => $netRevenue, 'cogs' => $cogs,
                'ads' => $ads, 'gross_profit' => $grossProfit,
                'profit' => $profit, 'missing_cost' => $missingCost,
            ],
            'tab_counts' => $tabCounts,
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
            ->when($request->query('status'), fn ($qq, $v) => $v === 'BANNED'
                ? $qq->whereIn('status', ['BANNED', 'SHOPEE_DELETE'])
                : $qq->where('status', $v))
            ->when($request->query('cost') === 'filled', fn ($qq) => $qq->where('cost_price', '>', 0))
            ->when($request->query('cost') === 'empty', fn ($qq) => $qq->where(fn ($w) => $w->whereNull('cost_price')->orWhere('cost_price', 0)))
            // Stok: "out" = sold-out, "low" = running low (1..10), "in" = in stock.
            ->when($request->query('stock') === 'out', fn ($qq) => $qq->where('stock', '<=', 0))
            ->when($request->query('stock') === 'low', fn ($qq) => $qq->where('stock', '>', 0)->where('stock', '<=', 10))
            ->when($request->query('stock') === 'in', fn ($qq) => $qq->where('stock', '>', 0))
            ->when($request->query('search'), fn ($qq, $s) => $qq->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%")
                ->orWhere('item_sku', 'like', "%{$s}%")
                ->orWhere('model_name', 'like', "%{$s}%")
                ->orWhere('item_id', 'like', "%{$s}%")
                ->orWhere('model_id', 'like', "%{$s}%")))
            ->orderBy('name')->orderBy('model_name');

        $base = MarketplaceListing::where('user_id', $request->user()->id)
            ->when($request->query('shop_id'), fn ($qq, $v) => $qq->where('marketplace_shop_id', $v));

        // Paginate by Shopee product (shop + item), not by variation, so a product's variations stay together.
        $groups = (clone $q)->reorder()->select('marketplace_shop_id', 'item_id')->selectRaw('MIN(name) as group_name')
            ->groupBy('marketplace_shop_id', 'item_id')->orderBy('group_name')->orderBy('marketplace_shop_id')
            ->paginate(min(1000, max(1, $request->integer('per_page', 10))));
        $rows = $groups->isEmpty() ? collect() : (clone $q)->where(fn ($w) => $groups->each(
            fn ($g) => $w->orWhere(fn ($x) => $x->where('marketplace_shop_id', $g->marketplace_shop_id)->where('item_id', $g->item_id))
        ))->get();
        $listings = $groups->setCollection($rows);

        $countProducts = fn ($scope = null) => (clone $base)
            ->when($scope, $scope)
            ->select('marketplace_shop_id', 'item_id')
            ->groupBy('marketplace_shop_id', 'item_id')
            ->get()
            ->count();

        return [
            'listings' => $listings,
            // Dilihat / disukai / terjual per product (daily snapshots), "shop:item" => numbers.
            'item_stats' => (object) \App\Support\Shopee\ShopeeProductTools::statsFor($rows->map(fn ($l) => [(int) $l->marketplace_shop_id, (int) $l->item_id])->unique(fn ($k) => "{$k[0]}:{$k[1]}")->values()),
            'stats' => [
                'total' => (clone $base)->count(), 'linked' => (clone $base)->whereNotNull('product_id')->count(),
                'out_of_stock' => (clone $base)->where('stock', '<=', 0)->count(), 'stock' => (int) (clone $base)->sum('stock'),
                'with_cost' => (clone $base)->whereNotNull('cost_price')->where('cost_price', '>', 0)->count(),
                'low_stock' => (clone $base)->where('stock', '>', 0)->where('stock', '<=', 10)->count(),
                'tabs' => [
                    'all' => $countProducts(),
                    'live' => $countProducts(fn ($b) => $b->where('status', 'NORMAL')),
                    'action' => $countProducts(fn ($b) => $b->whereIn('status', ['BANNED', 'SHOPEE_DELETE'])),
                    'reviewing' => $countProducts(fn ($b) => $b->where('status', 'REVIEWING')),
                    'unlist' => $countProducts(fn ($b) => $b->where('status', 'UNLIST')),
                ],
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
        // Linked to a stok master: change the shared stock, every shop follows.
        if ($listing->stock_master_id && ($master = $listing->stockMaster)) {
            \App\Support\MasterStock::setStock($master, $data['stock'], 'Ubah dari Produk Shopee');
            \App\Support\MasterStock::push($master);

            return $listing->refresh();
        }
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

    /**
     * Financial income statement (Laporan Laba Rugi Komprehensif) matching FinKami's exact structure:
     * - Pendapatan Kotor (GMV)
     * - Rincian Biaya Platform (Shopee Escrow Detail items)
     * - Penyesuaian
     * - Pendapatan Bersih
     * - Harga Pokok Penjualan (HPP) with selectable basis (e.g. HPP Selesai)
     * - Laba Kotor
     * - Beban Operasional (Shopee Ads Manager total spend)
     * - Laba Operasional & Laba Bersih
     */
    public function statement(Request $request, MarketplaceShop $shop)
    {
        $this->own($request, $shop);
        $data = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'basis' => 'nullable|in:settled,settled_cogs_completed,all_active',
        ]);
        [$from, $to] = \App\Support\Dates::range($data['from'] ?? null, $data['to'] ?? null);
        $basis = $data['basis'] ?? 'settled_cogs_completed';
        $user = $request->user();

        // Include orders completed/settled in [$from, $to] (even if created earlier) plus active orders created in [$from, $to].
        $rows = Analytics::attachFeeBreakdowns(Analytics::transactions($user, $from, $to, $shop->id, false, false));

        $s = self::statementTotals($rows, $basis);

        // Beban operasional: the shop's real Shopee Ads Manager spend in the period (not per-conversion estimates).
        $campIds = \App\Models\ShopeeAdCampaign::where('marketplace_shop_id', $shop->id)->pluck('id');
        $adSpend = (int) \App\Models\ShopeeAdStat::whereIn('shopee_ad_campaign_id', $campIds)
            ->whereBetween('date', [\App\Support\Dates::day($from), \App\Support\Dates::day($to)])->sum('expense');
        if ($adSpend === 0) {
            $adSpend = (int) \App\Models\AdSpend::where('user_id', $user->id)->where('marketplace_shop_id', $shop->id)
                ->where('platform', 'shopee_ads')
                ->whereBetween('spent_on', [\App\Support\Dates::day($from), \App\Support\Dates::day($to)])->sum('amount');
        }
        $opexOther = (int) \App\Models\Expense::where('user_id', $user->id)->where('marketplace_shop_id', $shop->id)
            ->whereBetween('spent_on', [\App\Support\Dates::day($from), \App\Support\Dates::day($to)])->sum('amount');

        $netRevenue = $s['gmv'] + $s['fees_total'] + $s['adj_total'];
        $grossProfit = $netRevenue - $s['cogs'];
        $opex = $adSpend + $opexOther;
        $operatingProfit = $grossProfit - $opex;

        return [
            'shop' => ['id' => $shop->id, 'name' => $shop->shop_name, 'shop_id' => $shop->shop_id, 'region' => $shop->region],
            'range' => ['from' => \App\Support\Dates::day($from), 'to' => \App\Support\Dates::day($to)],
            'basis' => $basis,
            'counts' => ['total_orders' => $s['orders'], 'completed_orders' => $s['completed'], 'missing_cost_orders' => $s['missing_cost']],
            'revenue' => ['gmv' => $s['gmv'], 'total' => $s['gmv']],
            'fees' => ['items' => $s['fee_items'], 'total' => $s['fees_total']],
            'adjustments' => ['items' => $s['adj_items'], 'total' => $s['adj_total']],
            'net_revenue' => $netRevenue,
            'cogs' => ['total' => $s['cogs']],
            'gross_profit' => $grossProfit,
            'opex' => ['ads' => $adSpend, 'other' => $opexOther, 'total' => $opex],
            'operating_profit' => $operatingProfit,
            'net_profit' => $operatingProfit,
        ];
    }

    /**
     * FinKami-style totals from transaction rows.
     * settled: only orders whose income is released; settled_cogs_completed: all live orders, HPP only for
     * completed ones; all_active: all live orders with HPP. Cancelled orders never count.
     */
    public static function statementTotals($rows, string $basis): array
    {
        $adjKeys = ['drc_adjustable_refund', 'seller_lost_compensation', 'other'];
        $rank = array_flip(['seller_order_processing_fee', 'seller_return_refund', 'buyer_paid_shipping_fee', 'service_fee',
            'shopee_shipping_rebate', 'actual_shipping_fee', 'withholding_tax', 'escrow_tax', 'reverse_shipping_fee',
            'delivery_seller_protection_fee_premium_amount', 'shipping_seller_protection_fee_amount', 'commission_fee',
            'order_ams_commission_fee', 'seller_transaction_fee', 'campaign_fee']);
        $out = ['gmv' => 0, 'cogs' => 0, 'orders' => 0, 'completed' => 0, 'missing_cost' => 0];
        $fees = [];
        $adj = [];
        foreach ($rows as $r) {
            $st = (string) ($r['status'] ?? '');
            if (in_array($st, ['CANCELLED', 'IN_CANCEL', 'UNPAID'], true)) {
                continue;
            }
            $completed = $st === 'COMPLETED';
            if ($basis === 'settled' && ! ($r['fee_final'] ?? false)) {
                continue;
            }
            $out['orders']++;
            $out['completed'] += $completed ? 1 : 0;
            $out['gmv'] += (int) $r['revenue'];
            if ($basis === 'all_active' || $completed) {
                $out['cogs'] += (int) $r['cogs'];
                $out['missing_cost'] += ! empty($r['missing_cost']) ? 1 : 0;
            }
            foreach ($r['fee_breakdown'] ?? [] as $l) {
                $key = $l['key'] ?? 'other';
                $line = ['key' => $key, 'label' => $l['label'] ?? $key, 'amount' => 0];
                if (in_array($key, $adjKeys, true)) {
                    $adj[$key] ??= $line;
                    $adj[$key]['amount'] += (int) ($l['amount'] ?? 0);
                } else {
                    $fees[$key] ??= $line;
                    $fees[$key]['amount'] += (int) ($l['amount'] ?? 0);
                }
            }
        }
        $fees = collect($fees)->filter(fn ($x) => $x['amount'] !== 0)->sortBy(fn ($x) => $rank[$x['key']] ?? 99)->values();
        $adj = collect($adj)->filter(fn ($x) => $x['amount'] !== 0)->values();

        return $out + [
            'fee_items' => $fees, 'fees_total' => (int) $fees->sum('amount'),
            'adj_items' => $adj, 'adj_total' => (int) $adj->sum('amount'),
        ];
    }

    private function own(Request $request, MarketplaceShop $shop): void
    {
        abort_unless($shop->user_id === $request->user()->id, 404);
    }
}
