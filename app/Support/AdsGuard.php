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
 * then compares the ad's REAL ROAS (sales ÷ ad spend incl. 11% PPN, which Shopee charges on top of the spend it reports)
 * over the last N days with it:
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

    /** PPN Shopee adds to the ad spend (charged on the ads balance top-up, not in the reported spend). */
    public const PPN = 0.11;

    /** Evaluation thresholds (%, same as Kang Pantau): CTR low < 1.5 / high ≥ 3, CR low < 2.5 / high ≥ 5. */
    public const CTR_LOW = 1.5, CTR_HIGH = 3.0, CR_LOW = 2.5, CR_HIGH = 5.0;

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
    public static function evaluate(User $user, ?int $days = null, ?string $from = null, ?string $to = null): array
    {
        $cfg = self::settings($user);
        if ($from && $to) {
            // A period picked on the ads page (same dates as the table above it).
            $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        } else {
            $days ??= $cfg['days'];
            $to = now(Dates::TZ)->toDateString();
            $from = now(Dates::TZ)->subDays($days - 1)->toDateString();
        }
        $shops = MarketplaceShop::where('user_id', $user->id)->where('is_demo', false)->get()->keyBy('id');
        $campaigns = ShopeeAdCampaign::whereIn('marketplace_shop_id', $shops->keys())->where('status', 'ongoing')
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'expense')
            ->withSum(['stats as gmv' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'gmv')
            ->withSum(['stats as orders' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'orders')
            ->withSum(['stats as impressions' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'impressions')
            ->withSum(['stats as clicks' => fn ($q) => $q->whereBetween('date', [$from, $to])], 'clicks')
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
            // What the ads really cost: Shopee's spend + PPN 11%.
            $expenseReal = (int) round($expense * (1 + self::PPN));
            $roasReal = $expenseReal > 0 ? round($gmv / $expenseReal, 2) : null;

            $status = match (true) {
                $expense < $cfg['min_spend'] => 'data_kurang',
                $orders === 0 => 'tanpa_penjualan',
                $hppRatio === null => 'hpp_kosong',
                $breakEven === null || $roasReal < $breakEven => 'rugi',
                $roasReal < $breakEven * 1.2 => 'tipis',
                default => 'sehat',
            };
            // Profit (or loss) the ads made after Shopee fees, HPP and PPN: gmv × margin − real ad spend.
            $profit = $hppRatio === null ? null : (int) round($gmv * $margin - $expenseReal);
            $impressions = (int) $c->impressions;
            $clicks = (int) $c->clicks;
            $metrics = [
                'impressions' => $impressions, 'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
                'cr' => $clicks > 0 ? round($orders / $clicks * 100, 2) : null,
                'cpc' => $clicks > 0 ? (int) round($expenseReal / $clicks) : null,
                'cost_per_order' => $orders > 0 ? (int) round($expenseReal / $orders) : null,
            ];
            // Shopee's store-wide automatic product ads cannot be edited through edit_manual_product_ads.
            $editable = ! in_array($c->ad_type, ['auto', 'shop_auto'], true);

            return [
                'id' => $c->id, 'campaign_id' => (int) $c->campaign_id, 'shop_id' => $c->marketplace_shop_id, 'name' => $c->name ?: "Iklan {$c->campaign_id}",
                'ad_type' => $c->ad_type, 'bidding_method' => $c->bidding_method, 'roas_target' => $c->roas_target ? (float) $c->roas_target : null,
                'expense' => $expense, 'expense_real' => $expenseReal, 'gmv' => $gmv, 'orders' => $orders, 'roas' => $roas, 'roas_real' => $roasReal,
                'fee_rate' => round($fee, 3), 'hpp_ratio' => $hppRatio === null ? null : round($hppRatio, 3),
                'break_even' => $breakEven, 'break_even_min' => round(1 / max(0.01, 1 - $fee), 2),
                // Shopee's ROAS target ignores PPN: this one gives a real ROAS 20% above break-even.
                'suggested_target' => $breakEven ? round($breakEven * 1.2 * (1 + self::PPN), 1) : null,
                'profit' => $profit, 'status' => $status, 'items' => $items, 'editable' => $editable,
                // Breakdown of the profit: sales − HPP of what the ads sold − Shopee fees − ad spend incl. PPN.
                'hpp_total' => $hppRatio === null ? null : (int) round($gmv * $hppRatio), 'fee_total' => (int) round($gmv * $fee),
            ] + $metrics + ['evaluation' => self::verdicts($metrics + compact('expense', 'expenseReal', 'gmv', 'orders', 'roasReal', 'breakEven', 'profit', 'status'))];
        })->sortBy(fn ($r) => [array_search($r['status'], self::STATUSES, true), -$r['expense']])->values();

        return ['days' => $days, 'from' => $from, 'to' => $to, 'settings' => $cfg, 'campaigns' => $rows->all(),
            'summary' => collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $rows->where('status', $s)->count()])->all()
                + ['loss' => (int) $rows->where('status', 'rugi')->sum(fn ($r) => min(0, $r['profit'] ?? 0)),
                    'wasted' => (int) $rows->where('status', 'tanpa_penjualan')->sum('expense_real')]];
    }

    /**
     * Evaluasi iklan: three verdicts, each with a tone (good | ok | bad | wait) and concrete advice.
     *  1. Hook: is the ad attractive (CTR)?  2. Konversi: do clicks become orders (CR)?  3. ROAS & laba vs break-even.
     * @return array<int, array{key: string, title: string, status: string, tone: string, desc: string}>
     */
    public static function verdicts(array $m): array
    {
        $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
        $pct = fn ($n) => str_replace('.', ',', (string) $n).'%';
        ['ctr' => $ctr, 'cr' => $cr, 'clicks' => $clicks, 'impressions' => $imp, 'orders' => $orders] = $m;

        $hook = match (true) {
            $imp < 100 => ['wait', 'Tayangan masih sedikit', "Baru {$imp} tayangan, belum cukup untuk menilai daya tarik iklan. Tunggu beberapa hari atau naikkan budget/bid sedikit agar iklan lebih sering tampil."],
            $ctr < self::CTR_LOW => ['bad', 'CTR rendah, kurang menarik', "CTR hanya {$pct($ctr)} (standar minimal 1,5%): banyak yang melihat tapi jarang mengklik. Ganti foto utama dengan visual yang lebih kontras, tambahkan badge voucher/gratis ongkir/garansi di thumbnail, dan perjelas 5 kata pertama judul. Untuk iklan pencarian, buang kata kunci yang tidak relevan dengan produk."],
            $ctr >= self::CTR_HIGH => ['good', 'Sangat menarik', "CTR {$pct($ctr)} dari {$imp} tayangan ({$clicks} klik): thumbnail dan kata kunci sudah relevan. Pertahankan foto utama ini."],
            default => ['ok', 'Normal', "CTR {$pct($ctr)} masih wajar dengan biaya per klik {$rp($m['cpc'])}. Uji foto utama lain untuk mendorong CTR ke atas 3%."],
        };
        $conv = match (true) {
            $clicks < 20 => ['wait', 'Klik masih sedikit', "Baru {$clicks} klik, belum cukup untuk menilai konversi. Tunggu sampai minimal 20–30 klik."],
            $orders === 0 => ['bad', 'Belum ada pesanan', "{$clicks} klik tanpa satu pun pesanan: pembeli tertarik tapi batal di halaman produk. Cek harga dibanding kompetitor, ulasan bintang 1–3, kelengkapan foto & deskripsi, stok variasi, dan pasang voucher toko kecil untuk memancing checkout."],
            $cr < self::CR_LOW => ['bad', 'Konversi rendah (bocor di halaman produk)', "CR hanya {$pct($cr)} ({$orders} pesanan dari {$clicks} klik, standar minimal 2,5%). Pembeli tertarik tapi tidak membeli: bandingkan harga dengan kompetitor, balas/perbaiki ulasan negatif, perjelas deskripsi & ukuran, dan beri voucher toko atau bundling."],
            $cr >= self::CR_HIGH => ['good', 'Konversi tinggi', "CR {$pct($cr)}: {$orders} pesanan dari {$clicks} klik. Pembeli yang datang punya niat beli tinggi, halaman produk sudah meyakinkan."],
            default => ['ok', 'Konversi normal', "CR {$pct($cr)} ({$orders} pesanan dari {$clicks} klik). Tambah ulasan berfoto dan voucher toko untuk mendorong ke atas 5%."],
        };
        $real = "biaya iklan riil {$rp($m['expenseReal'])} (termasuk PPN 11%)";
        $money = match ($m['status']) {
            'data_kurang' => ['wait', 'Data belum cukup', "Biaya iklan baru {$rp($m['expense'])}, belum cukup untuk menilai untung-rugi."],
            'tanpa_penjualan' => ['bad', 'Boros, belum ada penjualan', "Sudah menghabiskan {$real} tanpa penjualan. Jeda iklan ini atau turunkan bid, lalu perbaiki foto & halaman produk sebelum dijalankan lagi."],
            'hpp_kosong' => ['wait', 'HPP belum diisi', "ROAS riil {$m['roasReal']}x dengan {$real}. Isi HPP produk supaya Pesanin bisa menghitung titik impas dan laba bersih yang pasti."],
            'rugi' => ['bad', 'Merugi', $m['breakEven']
                ? "ROAS riil {$m['roasReal']}x di bawah titik impas {$m['breakEven']}x: setelah HPP, potongan Shopee dan {$real}, iklan ini rugi {$rp(abs((int) $m['profit']))}. Naikkan target ROAS, pangkas kata kunci yang boros, atau naikkan harga jual."
                : 'Produk ini sudah rugi sebelum iklan (HPP + potongan Shopee melebihi harga jual). Naikkan harga atau turunkan HPP dulu, iklan hanya menambah rugi.'],
            'tipis' => ['ok', 'Untung tipis', "ROAS riil {$m['roasReal']}x sedikit di atas titik impas {$m['breakEven']}x, laba bersih {$rp($m['profit'])}. Jangan naikkan budget dulu; rapikan kata kunci dan naikkan sedikit target ROAS agar margin lebih aman."],
            default => ['good', 'Untung, siap di-scale', "ROAS riil {$m['roasReal']}x jauh di atas titik impas {$m['breakEven']}x, laba bersih {$rp($m['profit'])} setelah {$real}. Naikkan budget harian bertahap (±20% per 2–3 hari) sambil jaga ROAS tetap di atas titik impas."],
        };

        return collect([
            ['hook', 'Daya tarik iklan (CTR)', $hook], ['conversion', 'Konversi (CR)', $conv], ['profit', 'ROAS & laba bersih', $money],
        ])->map(fn ($v) => ['key' => $v[0], 'title' => $v[1], 'tone' => $v[2][0], 'status' => $v[2][1], 'desc' => $v[2][2]])->all();
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
                ? "• {$r['name']}: {$rp($r['expense_real'])} tanpa penjualan".($did ? " ({$did})" : '')
                : "• {$r['name']}: ROAS riil {$r['roas_real']} < impas {$r['break_even']}, rugi ±{$rp(abs($r['profit']))}".($did ? " ({$did})" : '');
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
