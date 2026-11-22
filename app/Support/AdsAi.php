<?php

namespace App\Support;

use App\Models\MarketplaceShop;
use App\Models\ShopeeAdCampaign;
use App\Models\ShopeeAdStat;
use App\Models\User;
use App\Support\Ai\Gemini;
use Illuminate\Support\Carbon;

/**
 * Analisa AI Iklan Shopee: every running ad of the period (real ROAS incl. PPN vs break-even, CTR, CR, CPC, cost per
 * order, profit after HPP & fees, 7-day trend), the share of the shop's sales that comes from ads, then asks the
 * seller's Gemini key for a decision per ad, a budget shift plan and account-level fixes. Decisions that Pesanin can
 * apply in Shopee (pause / resume / ROAS target) come back with the ad's id so the page can apply them in one click.
 */
class AdsAi
{
    public const DECISIONS = ['pause', 'raise_target', 'lower_target', 'scale', 'fix_creative', 'fix_offer', 'keep', 'wait'];

    public static function snapshot(User $user, ?int $shopId, string $from, string $to): array
    {
        $guard = AdsGuard::evaluate($user, null, $from, $to);
        $shops = MarketplaceShop::where('user_id', $user->id)->pluck('shop_name', 'id');
        $rows = collect($guard['campaigns'])->when($shopId, fn ($c) => $c->where('shop_id', $shopId))->values();
        $ids = $rows->pluck('id')->all();
        $budget = ShopeeAdCampaign::whereIn('id', $ids ?: [0])->get(['id', 'budget', 'placement'])->keyBy('id');

        // Last 7 days vs the 7 before (within the data we have), per ad.
        $end = Carbon::parse($to);
        $w1 = [$end->copy()->subDays(6)->toDateString(), $end->toDateString()];
        $w0 = [$end->copy()->subDays(13)->toDateString(), $end->copy()->subDays(7)->toDateString()];
        $week = fn (array $range) => ShopeeAdStat::whereIn('shopee_ad_campaign_id', $ids ?: [0])->whereBetween('date', $range)
            ->selectRaw('shopee_ad_campaign_id id, SUM(expense) expense, SUM(gmv) gmv, SUM(orders) orders, SUM(clicks) clicks')
            ->groupBy('shopee_ad_campaign_id')->get()->keyBy('id');
        [$now, $before] = [$week($w1), $week($w0)];

        $campaigns = $rows->map(function ($r) use ($shops, $budget, $now, $before) {
            $n = $now[$r['id']] ?? null;
            $b = $before[$r['id']] ?? null;
            $roas = fn ($x) => $x && $x->expense > 0 ? round($x->gmv / ($x->expense * (1 + AdsGuard::PPN)), 2) : null;

            return [
                'id' => $r['id'], 'shop' => $shops[$r['shop_id']] ?? 'Shopee', 'name' => $r['name'], 'ad_type' => $r['ad_type'],
                'bidding' => $r['bidding_method'], 'roas_target_now' => $r['roas_target'], 'daily_budget' => (int) ($budget[$r['id']]->budget ?? 0) ?: null,
                'can_change_in_pesanin' => $r['editable'],
                'spend_incl_ppn' => $r['expense_real'], 'ad_sales' => $r['gmv'], 'orders' => $r['orders'],
                'roas_real' => $r['roas_real'], 'break_even_roas' => $r['break_even'], 'suggested_target' => $r['suggested_target'],
                'profit_after_hpp_fees' => $r['profit'], 'hpp_known' => $r['hpp_ratio'] !== null,
                'impressions' => $r['impressions'], 'clicks' => $r['clicks'], 'ctr_pct' => $r['ctr'], 'cr_pct' => $r['cr'], 'cpc' => $r['cpc'], 'cost_per_order' => $r['cost_per_order'],
                'pesanin_status' => $r['status'],
                'products' => collect($r['items'])->take(5)->map(fn ($i) => array_filter(['name' => $i['name'], 'price' => $i['price'] ?: null, 'hpp' => $i['hpp']]))->values()->all(),
                'last_7d' => $n ? ['spend' => (int) $n->expense, 'sales' => (int) $n->gmv, 'orders' => (int) $n->orders, 'roas_real' => $roas($n)] : null,
                'prev_7d' => $b ? ['spend' => (int) $b->expense, 'sales' => (int) $b->gmv, 'orders' => (int) $b->orders, 'roas_real' => $roas($b)] : null,
            ];
        })->all();

        // How much of the shop's sales comes from ads (Shopee orders in the period).
        $shopSales = (int) \App\Models\Order::where('user_id', $user->id)->where('channel', 'shopee')
            ->when($shopId, fn ($q) => $q->where('marketplace_shop_id', $shopId))
            ->whereBetween('created_at', [Carbon::parse($from, Dates::TZ)->startOfDay()->utc(), Carbon::parse($to, Dates::TZ)->endOfDay()->utc()])
            ->whereNotIn('status', ['cancelled', 'refunded'])->sum('subtotal');
        $c = collect($campaigns);
        $spend = (int) $c->sum('spend_incl_ppn');
        $sales = (int) $c->sum('ad_sales');

        return [
            'period' => ['from' => $from, 'to' => $to, 'days' => $guard['days']],
            'totals' => [
                'running_ads' => $c->count(), 'spend_incl_ppn' => $spend, 'ad_sales' => $sales, 'ad_orders' => (int) $c->sum('orders'),
                'roas_real' => $spend > 0 ? round($sales / $spend, 2) : null, 'profit_after_hpp_fees' => (int) $c->sum('profit_after_hpp_fees'),
                'shop_sales_total' => $shopSales, 'ads_share_of_sales_pct' => $shopSales > 0 ? round(min(100, $sales / $shopSales * 100), 1) : null,
                'losing' => $c->where('pesanin_status', 'rugi')->count(), 'no_sales' => $c->where('pesanin_status', 'tanpa_penjualan')->count(),
                'thin' => $c->where('pesanin_status', 'tipis')->count(), 'healthy' => $c->where('pesanin_status', 'sehat')->count(),
                'hpp_missing' => $c->where('hpp_known', false)->count(),
                'paused_ads' => ShopeeAdCampaign::whereIn('marketplace_shop_id', $shopId ? [$shopId] : $shops->keys())->where('status', '!=', 'ongoing')->count(),
            ],
            'campaigns' => $campaigns,
        ];
    }

    public static function analyze(string $apiKey, array $snap, ?string $question = null): array
    {
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
        $q = $question ? "\nPERTANYAAN SELLER (jawab di `custom_answer`): \"".str_replace('"', "'", $question).'"' : '';
        $prompt = <<<PROMPT
Anda spesialis Iklan Shopee (Shopee Ads / Iklan Produk, GMV Max, ROAS target) untuk seller Indonesia.
Periode {$snap['period']['from']} s/d {$snap['period']['to']} ({$snap['period']['days']} hari). Angka dalam Rupiah.

DATA IKLAN (nyata, JSON):
{$json}
{$q}

Arti kolom: spend_incl_ppn = biaya iklan + PPN 11%. roas_real = penjualan iklan ÷ biaya termasuk PPN. break_even_roas = ROAS minimal agar tidak rugi setelah HPP & potongan Shopee. suggested_target = target ROAS Shopee yang disarankan (memberi ROAS riil ±20% di atas impas). profit_after_hpp_fees = laba/rugi iklan setelah HPP, potongan Shopee, dan biaya iklan. pesanin_status: rugi / tipis / sehat / tanpa_penjualan / data_kurang / hpp_kosong. last_7d vs prev_7d = tren mingguan. ads_share_of_sales_pct = porsi penjualan toko dari iklan.

ATURAN:
1. Pakai HANYA angka & nama iklan dari data. Jangan mengarang.
2. Satu keputusan per iklan (`campaigns`), pakai `id` persis dari data. decision salah satu:
   - "pause": rugi jelas / tanpa penjualan dengan biaya berarti.
   - "raise_target": ROAS riil di bawah impas tapi masih ada penjualan → naikkan target ke `target_roas` (pakai suggested_target bila ada).
   - "lower_target": sangat untung tapi volume kecil/impresi rendah → turunkan target sedikit untuk dapat volume (tetap di atas impas).
   - "scale": sehat & tren naik → naikkan budget harian.
   - "fix_creative": CTR rendah (< 1%) → perbaiki foto utama/judul.
   - "fix_offer": klik banyak tapi CR rendah (< 2%) → perbaiki harga, voucher, ulasan, deskripsi.
   - "keep": sudah bagus. "wait": data belum cukup.
   `target_roas` hanya untuk raise_target/lower_target (angka, mis. 8.5); selain itu null. Kalau can_change_in_pesanin = false, tetap beri keputusan tapi jelaskan diubah di Seller Centre.
3. budget_plan: pindahkan budget dari iklan rugi ke iklan sehat dengan nominal Rupiah per hari bila memungkinkan.
4. insights: masalah level akun (ketergantungan iklan, CTR/CR rata-rata, HPP kosong, iklan terlalu banyak/sedikit, tren).
5. Bahasa Indonesia lugas, sapaan "Anda".

Balas HANYA JSON:
{
  "score": 0-100,
  "label": "Sehat" | "Perlu Perhatian" | "Kritis",
  "summary": "2-3 kalimat: total biaya, ROAS riil vs impas, laba/rugi iklan, masalah utama.",
  "custom_answer": null,
  "budget_plan": {"summary": "...", "moves": [{"from": "nama iklan atau null", "to": "nama iklan atau null", "amount": "Rp.../hari", "reason": "..."}]},
  "campaigns": [{"id": 0, "name": "...", "decision": "pause", "target_roas": null, "headline": "keputusan singkat", "reason": "alasan dengan angka", "steps": ["langkah 1", "langkah 2"]}],
  "insights": [{"title": "...", "detail": "dengan angka", "action": "..."}],
  "action_plan": [{"day": "Hari 1", "task": "..."}]
}
Urutkan campaigns dari dampak terbesar (rugi terbesar dulu). Maksimal 25 campaigns, 5 insights, 7 action_plan.
PROMPT;

        $d = Gemini::json($apiKey, $prompt, ['score', 'summary', 'campaigns']);

        // Keep only decisions about ads that exist, and only applicable ROAS targets.
        $known = collect($snap['campaigns'])->keyBy('id');
        $d['campaigns'] = collect(is_array($d['campaigns']) ? $d['campaigns'] : [])->filter(fn ($c) => is_array($c) && isset($known[(int) ($c['id'] ?? 0)]))
            ->map(function ($c) use ($known) {
                $k = $known[(int) $c['id']];
                $decision = in_array($c['decision'] ?? '', self::DECISIONS, true) ? $c['decision'] : 'keep';
                $target = in_array($decision, ['raise_target', 'lower_target'], true) && is_numeric($c['target_roas'] ?? null) ? round(max(0.5, min(100, (float) $c['target_roas'])), 1) : null;

                return ['id' => (int) $c['id'], 'name' => $k['name'], 'shop' => $k['shop'], 'decision' => $decision, 'target_roas' => $target,
                    'headline' => (string) ($c['headline'] ?? ''), 'reason' => (string) ($c['reason'] ?? ''), 'steps' => array_values(array_filter((array) ($c['steps'] ?? []), 'is_string')),
                    // Pesanin can apply: pause any editable ad, set the ROAS target of an editable ad.
                    'apply' => $k['can_change_in_pesanin'] ? match ($decision) {
                        'pause' => ['action' => 'pause'],
                        'raise_target', 'lower_target' => $target ? ['action' => 'change_roas_target', 'target' => $target] : null,
                        default => null,
                    } : null,
                    'metrics' => array_intersect_key($k, array_flip(['spend_incl_ppn', 'ad_sales', 'orders', 'roas_real', 'break_even_roas', 'profit_after_hpp_fees', 'ctr_pct', 'cr_pct', 'roas_target_now']))];
            })->values()->all();
        foreach (['insights', 'action_plan'] as $k) {
            $d[$k] = array_values(array_filter(is_array($d[$k] ?? null) ? $d[$k] : [], 'is_array'));
        }
        $d['budget_plan'] = is_array($d['budget_plan'] ?? null) ? $d['budget_plan'] + ['moves' => []] : null;
        $d['score'] = max(0, min(100, (int) $d['score']));

        return $d;
    }
}
