<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\ShopeeAdCampaign;
use App\Models\User;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Penjaga Iklan Shopee. For every ad it works out the BREAK-EVEN ROAS from the product's own numbers:
 *
 *     margin before ads = 1 − Shopee fee rate − HPP / price      break-even ROAS = 1 / margin
 *
 * (fee rate = the shop's real Shopee fees ÷ revenue over the last 30 days; HPP from the listing or linked product),
 * then compares the ad's ROAS over the last N days with it:
 *   sehat  ≥ 1.2 × break-even · tipis  between break-even and 1.2× · rugi  below break-even
 *   tanpa_penjualan  spent ≥ the minimum without one order · hpp_kosong  no HPP, cannot judge · data_kurang  spent too little
 * The seller is alerted (notification + Telegram) once a day per losing ad and can pause it or raise its ROAS target;
 * optionally Pesanin does that automatically.
 */
class AdsGuard
{
    public const STATUSES = ['rugi', 'tanpa_penjualan', 'tipis', 'hpp_kosong', 'sehat', 'data_kurang'];

    /** Shopee fee share when a shop has no sales history yet. */
    public const DEFAULT_FEE_RATE = 0.22;

    public static function settings(User $user): array
    {
        $s = ($user->settings['ads_guard'] ?? []) + ['notify' => true, 'days' => 7, 'min_spend' => 50000, 'action' => 'off'];
        $s['days'] = in_array((int) $s['days'], [3, 7, 14], true) ? (int) $s['days'] : 7;
        $s['min_spend'] = max(10000, (int) $s['min_spend']);
        $s['action'] = in_array($s['action'], ['off', 'pause', 'raise_target'], true) ? $s['action'] : 'off';

        return $s;
    }

    /** Shopee fees ÷ revenue of a shop over the last 30 days (cached 6 hours). */
    public static function feeRate(User $user, int $shopId): float
    {
        return Cache::remember("ads-guard:fee:{$shopId}", now()->addHours(6), function () use ($user, $shopId) {
            try {
                [$from, $to] = Dates::lastDays(30);
                $s = Analytics::profit($user, $from, $to, 'shopee', $shopId)['summary'];

                return $s['revenue'] > 0 && $s['fees'] > 0 ? min(0.6, $s['fees'] / $s['revenue']) : self::DEFAULT_FEE_RATE;
            } catch (Throwable) {
                return self::DEFAULT_FEE_RATE;
            }
        });
    }

    /** Every ad of the seller with its verdict, worst first. */
    public static function evaluate(User $user, ?int $days = null): array
    {
        $cfg = self::settings($user);
        $days ??= $cfg['days'];
        $to = now(Dates::TZ)->toDateString();
        $from = now(Dates::TZ)->subDays($days - 1)->toDateString();
        $shops = MarketplaceShop::where('user_id', $user->id)->where('is_demo', false)->get()->keyBy('id');
        $campaigns = ShopeeAdCampaign::whereIn('marketplace_shop_id', $shops->keys())->where('status', 'ongoing')
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'expense')
            ->withSum(['stats as gmv' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'gmv')
            ->withSum(['stats as orders' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'orders')
            ->get();
        $itemIds = $campaigns->pluck('item_ids')->flatten()->filter()->unique()->all();
        $listings = MarketplaceListing::whereIn('marketplace_shop_id', $shops->keys())->whereIn('item_id', $itemIds ?: [0])
            ->with('product:id,cost_price')->get()->groupBy(fn ($l) => $l->marketplace_shop_id.':'.$l->item_id);

        $rows = $campaigns->map(function ($c) use ($user, $listings, $cfg) {
            $fee = self::feeRate($user, $c->marketplace_shop_id);
            // Per promoted product: average price, and HPP share (variations with an HPP only).
            $items = collect($c->item_ids ?? [])->map(function ($id) use ($c, $listings) {
                $g = $listings[$c->marketplace_shop_id.':'.$id] ?? collect();
                $withCost = $g->filter(fn ($l) => ($l->cost_price ?: $l->product?->cost_price) > 0 && $l->price > 0);

                return [
                    'item_id' => (int) $id, 'name' => $g->first()?->name, 'image' => $g->first()?->image,
                    'price' => (int) round($g->avg('price') ?? 0),
                    'hpp' => $withCost->isEmpty() ? null : (int) round($withCost->avg(fn ($l) => $l->cost_price ?: $l->product->cost_price)),
                    'hpp_ratio' => $withCost->isEmpty() ? null : $withCost->avg(fn ($l) => ($l->cost_price ?: $l->product->cost_price) / $l->price),
                ];
            })->values();
            $known = $items->whereNotNull('hpp_ratio');
            $hppRatio = $known->isEmpty() ? null : $known->avg('hpp_ratio');
            $margin = 1 - $fee - ($hppRatio ?? 0);
            $breakEven = $margin > 0 ? round(1 / $margin, 2) : null;
            $expense = (int) $c->expense;
            $gmv = (int) $c->gmv;
            $orders = (int) $c->orders;
            $roas = $expense > 0 ? round($gmv / $expense, 2) : null;

            $status = match (true) {
                $expense < $cfg['min_spend'] => 'data_kurang',
                $orders === 0 => 'tanpa_penjualan',
                $hppRatio === null => 'hpp_kosong',
                $breakEven === null || $roas < $breakEven => 'rugi',
                $roas < $breakEven * 1.2 => 'tipis',
                default => 'sehat',
            };
            // Profit (or loss) the ads made after Shopee fees and HPP: gmv × margin − ad spend.
            $profit = $hppRatio === null ? null : (int) round($gmv * $margin - $expense);
            // Shopee's store-wide automatic product ads cannot be edited through edit_manual_product_ads.
            $editable = ! in_array($c->ad_type, ['auto', 'shop_auto'], true);

            return [
                'id' => $c->id, 'campaign_id' => (int) $c->campaign_id, 'shop_id' => $c->marketplace_shop_id, 'name' => $c->name ?: "Iklan {$c->campaign_id}",
                'ad_type' => $c->ad_type, 'bidding_method' => $c->bidding_method, 'roas_target' => $c->roas_target ? (float) $c->roas_target : null,
                'expense' => $expense, 'gmv' => $gmv, 'orders' => $orders, 'roas' => $roas,
                'fee_rate' => round($fee, 3), 'hpp_ratio' => $hppRatio === null ? null : round($hppRatio, 3),
                'break_even' => $breakEven, 'break_even_min' => round(1 / max(0.01, 1 - $fee), 2),
                // A target this high leaves a 20% safety margin over break-even.
                'suggested_target' => $breakEven ? round($breakEven * 1.2, 1) : null,
                'profit' => $profit, 'status' => $status, 'items' => $items, 'editable' => $editable,
            ];
        })->sortBy(fn ($r) => [array_search($r['status'], self::STATUSES, true), -$r['expense']])->values();

        return ['days' => $days, 'from' => $from, 'to' => $to, 'settings' => $cfg, 'campaigns' => $rows->all(),
            'summary' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all()
                + ['loss' => (int) $rows->where('status', 'rugi')->sum(fn ($r) => min(0, $r['profit'] ?? 0)),
                    'wasted' => (int) $rows->where('status', 'tanpa_penjualan')->sum('expense')]];
    }

    /** Pause, resume, or set the ROAS target of one ad, in Shopee. */
    public static function act(User $user, int $campaignRowId, string $action, ?float $target = null): ShopeeAdCampaign
    {
        $c = ShopeeAdCampaign::whereKey($campaignRowId)->whereIn('marketplace_shop_id', MarketplaceShop::where('user_id', $user->id)->pluck('id'))->firstOrFail();
        $shop = MarketplaceShop::findOrFail($c->marketplace_shop_id);
        $extra = $action === 'change_roas_target' ? ['roas_target' => round((float) $target, 1)] : [];
        if (! $shop->is_demo) {
            (new ShopeeClient)->editManualAd($shop, (int) $c->campaign_id, $action, $extra);
        }
        $c->update(match ($action) {
            'pause' => ['status' => 'paused'],
            'resume' => ['status' => 'ongoing'],
            default => ['roas_target' => $extra['roas_target']],
        });
        ActivityLog::record($user, 'ads.guard', match ($action) {
            'pause' => "Jeda iklan \"{$c->name}\"", 'resume' => "Lanjutkan iklan \"{$c->name}\"", default => "Target ROAS \"{$c->name}\" → {$extra['roas_target']}",
        });

        return $c;
    }

    /**
     * After the ads sync: alert once a day per losing / no-sale ad, and apply the seller's automatic action.
     * @return int ads alerted
     */
    public static function check(User $user): int
    {
        $cfg = self::settings($user);
        if (! $cfg['notify'] && $cfg['action'] === 'off') {
            return 0;
        }
        $bad = collect(self::evaluate($user)['campaigns'])->whereIn('status', ['rugi', 'tanpa_penjualan']);
        $lines = [];
        foreach ($bad as $r) {
            $key = "ads-guard:alert:{$r['id']}:".now(Dates::TZ)->toDateString();
            if (Cache::has($key)) {
                continue;
            }
            Cache::put($key, true, now()->addDay());
            $did = null;
            if ($cfg['action'] !== 'off' && $r['editable']) {
                try {
                    $raise = $cfg['action'] === 'raise_target' && $r['suggested_target'] && $r['bidding_method'] === 'auto' && $r['status'] === 'rugi';
                    self::act($user, $r['id'], $raise ? 'change_roas_target' : 'pause', $raise ? $r['suggested_target'] : null);
                    $did = $raise ? "target ROAS dinaikkan ke {$r['suggested_target']}" : 'iklan dijeda otomatis';
                } catch (ShopeeException|Throwable $e) {
                    $did = 'gagal diubah otomatis: '.mb_substr($e->getMessage(), 0, 80);
                }
            }
            $rp = fn ($n) => 'Rp'.number_format($n, 0, ',', '.');
            $lines[] = $r['status'] === 'tanpa_penjualan'
                ? "• {$r['name']}: {$rp($r['expense'])} tanpa penjualan".($did ? " ({$did})" : '')
                : "• {$r['name']}: ROAS {$r['roas']} < impas {$r['break_even']}, rugi ±{$rp(abs($r['profit']))}".($did ? " ({$did})" : '');
        }
        if (! $lines || ! $cfg['notify']) {
            return count($lines);
        }
        $title = count($lines).' iklan Shopee merugi ('.self::settings($user)['days'].' hari terakhir)';
        Notifier::send($user->id, 'ads', $title, implode("\n", $lines), '/marketplace/ads?guard=1');
        try {
            $i = $user->integration('telegram');
            if ($i && $i->key('bot_token') && ! empty($i->settings['chat_id'])) {
                (new Telegram\TelegramClient($i->key('bot_token')))->sendMessage($i->settings['chat_id'], "⚠️ {$title}\n".implode("\n", $lines)."\n\nCek di Pesanin → Marketplace → Iklan.");
            }
        } catch (Throwable) {
            // Telegram is a bonus channel; the in-app notification is already there.
        }

        return count($lines);
    }
}
