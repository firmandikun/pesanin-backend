<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopeeAdCampaign;
use App\Models\ShopeeReturn;
use App\Models\StockMaster;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gathers real profit, product margin, ad waste, returns, and warehouse stock data,
 * then asks the seller's Gemini API key for an actionable Indonesian business diagnosis.
 */
class AiBusinessDiagnosis
{
    public static function verifyKey(string $apiKey): void
    {
        $key = trim($apiKey);
        if ($key === '') {
            throw new RuntimeException('Gemini API key wajib diisi.');
        }
        $res = Http::timeout(12)->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $key]);
        if (! $res->successful()) {
            $msg = $res->json('error.message') ?? 'API key Gemini tidak valid atau ditolak Google.';
            throw new RuntimeException($msg);
        }
    }

    /** Build a complete financial & product performance snapshot for [$from, $to]. */
    public static function snapshot(User $user, Carbon $from, Carbon $to): array
    {
        $profit = Analytics::profit($user, $from, $to);
        $feeRates = Analytics::shopFeeRates($user);
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();

        // Map ad spend per shop:item for the period
        $shopIds = MarketplaceShop::where('user_id', $user->id)->where('status', '!=', 'disconnected')->pluck('id');
        $adByItem = [];
        $campaigns = ShopeeAdCampaign::whereIn('marketplace_shop_id', $shopIds)
            ->withSum(['stats as expense' => fn ($q) => $q->whereBetween('date', [$fromStr, $toStr])], 'expense')
            ->get(['id', 'marketplace_shop_id', 'item_ids']);
        foreach ($campaigns as $c) {
            $ids = array_values(array_filter(array_map('intval', $c->item_ids ?? [])));
            if (! $ids) {
                continue;
            }
            $share = ((float) $c->expense * 1.11) / count($ids); // include PPN 11%
            foreach ($ids as $iid) {
                $k = "{$c->marketplace_shop_id}:{$iid}";
                $adByItem[$k] = ($adByItem[$k] ?? 0) + $share;
            }
        }

        // Lookup HPP by listing / product / SKU
        $listings = MarketplaceListing::where('user_id', $user->id)->with('product:id,cost_price')->get();
        $hppByKey = [];
        $hppBySku = [];
        foreach ($listings as $l) {
            $c = (int) ($l->cost_price ?: $l->product?->cost_price ?: 0);
            if ($c > 0) {
                $hppByKey["{$l->marketplace_shop_id}:{$l->item_id}:{$l->model_id}"] = $c;
                $hppByKey["{$l->marketplace_shop_id}:{$l->item_id}"] ??= $c;
                if ($l->sku) {
                    $hppBySku[mb_strtolower(trim($l->sku))] ??= $c;
                }
            }
        }
        $prodHpp = Product::where('user_id', $user->id)->pluck('cost_price', 'id');

        // Aggregate per product across orders in [$from, $to]
        $orders = Analytics::salesQuery($user, $from, $to)->get([
            'id', 'channel', 'marketplace_shop_id', 'product_id', 'items', 'quantity', 'total', 'shipping_cost', 'cod_fee', 'status',
        ]);
        $cancelledCount = Order::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->where(fn ($w) => $w->whereIn('status', ['cancelled', 'refunded'])->orWhere('external_status', 'CANCELLED'))
            ->count();
        $returnsCount = ShopeeReturn::where('user_id', $user->id)
            ->whereBetween('created_at', [$from->copy()->startOfDay()->utc(), $to->copy()->endOfDay()->utc()])
            ->count();

        $byProduct = [];
        $missingHppProducts = [];
        foreach ($orders as $o) {
            $feeRate = $o->channel === 'shopee' && $o->marketplace_shop_id
                ? ($feeRates[$o->marketplace_shop_id] ?? AdsGuard::DEFAULT_FEE_RATE)
                : 0.0;
            $items = is_array($o->items) && count($o->items) ? $o->items : [[
                'product_id' => $o->product_id,
                'item_id' => 0,
                'model_id' => 0,
                'name' => 'Produk #'.($o->product_id ?: $o->id),
                'sku' => null,
                'qty' => max(1, (int) $o->quantity),
                'subtotal' => max(0, (int) $o->total - (int) $o->shipping_cost - (int) $o->cod_fee),
            ]];
            foreach ($items as $it) {
                $qty = max(1, (int) ($it['qty'] ?? 1));
                $rev = (int) ($it['subtotal'] ?? ((int) ($it['discounted_price'] ?? $it['price'] ?? 0) * $qty));
                $iid = (int) ($it['item_id'] ?? 0);
                $mid = (int) ($it['model_id'] ?? 0);
                $pid = (int) ($it['product_id'] ?? 0);
                $sku = trim((string) ($it['sku'] ?? $it['model_sku'] ?? ''));
                $name = trim((string) ($it['name'] ?? ($sku ?: 'Produk')));
                $pKey = $iid > 0 ? "sp:{$o->marketplace_shop_id}:{$iid}" : ($pid > 0 ? "loc:{$pid}" : 'name:'.mb_strtolower($name));

                $unitHpp = 0;
                if ($iid > 0) {
                    $unitHpp = $hppByKey["{$o->marketplace_shop_id}:{$iid}:{$mid}"]
                        ?? $hppByKey["{$o->marketplace_shop_id}:{$iid}"]
                        ?? ($sku !== '' ? ($hppBySku[mb_strtolower($sku)] ?? 0) : 0);
                } elseif ($pid > 0) {
                    $unitHpp = (int) ($prodHpp[$pid] ?? 0);
                }
                if ($unitHpp === 0 && $rev > 0) {
                    $missingHppProducts[$pKey] = $name;
                }

                $byProduct[$pKey] ??= [
                    'key' => $pKey,
                    'name' => $name,
                    'sku' => $sku ?: null,
                    'channel' => $o->channel,
                    'units' => 0,
                    'revenue' => 0,
                    'cogs' => 0,
                    'est_fees' => 0,
                    'ad_spend' => $iid > 0 ? (int) round($adByItem["{$o->marketplace_shop_id}:{$iid}"] ?? 0) : 0,
                    'has_hpp' => $unitHpp > 0,
                ];
                $byProduct[$pKey]['units'] += $qty;
                $byProduct[$pKey]['revenue'] += $rev;
                $byProduct[$pKey]['cogs'] += $unitHpp * $qty;
                $byProduct[$pKey]['est_fees'] += (int) round($rev * $feeRate);
                if ($unitHpp > 0) {
                    $byProduct[$pKey]['has_hpp'] = true;
                }
            }
        }

        $productRows = collect($byProduct)->map(function ($p) {
            $net = $p['revenue'] - $p['cogs'] - $p['est_fees'] - $p['ad_spend'];
            $margin = $p['revenue'] > 0 ? round(($net / $p['revenue']) * 100, 1) : 0;

            return $p + ['net_profit' => $net, 'margin_pct' => $margin];
        })->values();

        $lossProducts = $productRows->filter(fn ($p) => $p['has_hpp'] && ($p['net_profit'] < 0 || $p['margin_pct'] < 5))
            ->sortBy('net_profit')->take(10)->values()->all();
        $bestProducts = $productRows->filter(fn ($p) => $p['revenue'] > 0)
            ->sortByDesc('net_profit')->take(10)->values()->all();

        // Ads guard leaks
        $guard = AdsGuard::evaluate($user, null, $fromStr, $toStr);
        $losingAds = collect($guard['campaigns'] ?? [])
            ->whereIn('status', ['rugi', 'tanpa_penjualan', 'tipis'])
            ->take(10)
            ->map(fn ($c) => [
                'name' => $c['name'],
                'status' => $c['status'],
                'expense_real' => $c['expense_real'],
                'gmv' => $c['gmv'],
                'orders' => $c['orders'],
                'roas_real' => $c['roas_real'],
                'break_even' => $c['break_even'],
                'profit' => $c['profit'],
            ])->values()->all();

        // Stock masters & warehouse modal
        $masters = StockMaster::where('user_id', $user->id)
            ->with(['listings' => fn ($l) => $l->select('id', 'stock_master_id', 'product_id', 'price', 'cost_price')->with('product:id,cost_price')])
            ->get();
        $warehouseModal = 0;
        $lowStockSkus = [];
        foreach ($masters as $m) {
            $hpp = (int) ($m->listings->map(fn ($l) => (int) ($l->cost_price ?: $l->product?->cost_price))->filter()->max() ?: 0);
            if ($m->stock > 0 && $hpp > 0) {
                $warehouseModal += $m->stock * $hpp;
            }
            if ($m->stock <= 5) {
                $lowStockSkus[] = ['sku' => $m->sku, 'name' => $m->name, 'stock' => $m->stock];
            }
        }

        $t = $profit['summary'];

        return [
            'period' => ['from' => $fromStr, 'to' => $toStr],
            'financials' => [
                'revenue' => (int) ($t['revenue'] ?? 0),
                'cogs' => (int) ($t['cogs'] ?? 0),
                'platform_fees' => (int) ($t['fees'] ?? 0),
                'ad_spend' => (int) ($t['ads'] ?? 0),
                'expenses' => (int) ($t['opex'] ?? 0),
                'net_profit' => (int) ($t['net_profit'] ?? 0),
                'net_margin_pct' => (float) ($t['margin'] ?? 0),
                'orders_count' => (int) ($t['orders'] ?? 0),
                'cancelled_orders' => $cancelledCount,
                'returns_count' => $returnsCount,
                'ad_waste_estimate' => (int) (($guard['summary']['loss'] ?? 0) + ($guard['summary']['wasted'] ?? 0)),
                'warehouse_modal' => $warehouseModal,
                'missing_hpp_count' => count($missingHppProducts),
            ],
            'loss_products' => $lossProducts,
            'best_products' => $bestProducts,
            'losing_ads' => $losingAds,
            'low_stock_skus' => array_slice($lowStockSkus, 0, 10),
        ];
    }

    /** Send the snapshot to Gemini and return structured diagnosis JSON. */
    public static function diagnose(string $apiKey, array $snapshot, ?string $question = null): array
    {
        $jsonPayload = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        $extraQ = $question ? "\nPertanyaan tambahan dari pemilik bisnis: \"{$question}\"" : '';

        $prompt = <<<PROMPT
Anda adalah konsultan keuangan & strategi e-commerce senior untuk seller Indonesia (Shopee & Media Sosial).
Analisis data bisnis nyata berikut (dalam Rupiah/IDR) untuk periode {$snapshot['period']['from']} s/d {$snapshot['period']['to']}:
{$jsonPayload}
{$extraQ}

Tugas Anda:
1. Hitung skor kesehatan bisnis (0-100) dan label ("Sehat", "Perlu Perhatian", atau "Kritis").
2. Identifikasi **kebocoran dana** paling nyata (dari iklan yang rugi / tanpa penjualan, produk yang marginnya negatif/tipis, potongan biaya platform, pesanan batal/retur, atau HPP yang belum terisi).
3. Berikan saran konkret untuk **produk yang rugi** (mis. naikkan harga berapa %, matikan iklan, cek biaya admin/gratis ongkir ekstra, atau hentikan produk).
4. Berikan strategi untuk **produk terlaris / paling untung** (cara scale-up, amankan stok, naikkan budget iklan).
5. Jika ada pertanyaan tambahan dari pemilik bisnis, jawab langsung di `custom_answer` (atau null jika tidak ada pertanyaan).
6. Susun rencana aksi 7 hari ke depan yang konkret dan terurut prioritasnya.

Balas HANYA dalam format JSON valid dengan struktur persis berikut:
{
  "health_score": 75,
  "health_label": "Perlu Perhatian",
  "executive_summary": "Ringkasan 2-3 kalimat tentang kondisi laba bersih, omzet, dan masalah utama bisnis.",
  "custom_answer": null,
  "financial_leaks": [
    {
      "title": "Judul kebocoran dana",
      "severity": "high",
      "estimated_impact": "Rp...",
      "detail": "Penjelasan singkat berbasis angka dari data.",
      "action": "Langkah konkret untuk menutup kebocoran."
    }
  ],
  "loss_products_advice": [
    {
      "product_name": "Nama produk",
      "issue": "Rugi Rp... / margin tipis ...% karena ...",
      "action": "Tindakan spesifik (naikkan harga / stop iklan / perbaiki HPP)."
    }
  ],
  "best_products_advice": [
    {
      "product_name": "Nama produk",
      "insight": "Kontribusi laba Rp... dengan margin ...%",
      "action": "Strategi scale-up & jaga stok."
    }
  ],
  "action_plan_7_days": [
    "Hari 1-2: ...",
    "Hari 3-4: ...",
    "Hari 5-7: ..."
  ]
}
PROMPT;

        $lastErr = 'Gagal menghubungi Gemini AI.';
        foreach (['gemini-3.8-flash', 'gemini-2.5-flash', 'gemini-2.5-pro'] as $model) {
            $res = Http::timeout(45)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".urlencode($apiKey),
                [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature' => 0.3,
                        'responseMimeType' => 'application/json',
                    ],
                ]
            );
            if (! $res->successful()) {
                $lastErr = $res->json('error.message') ?? "HTTP {$res->status()}";
                continue;
            }
            $text = $res->json('candidates.0.content.parts.0.text');
            if (! $text) {
                $lastErr = 'Respons Gemini kosong.';
                continue;
            }
            $clean = preg_replace('/^```json\s*|\s*```$/', '', trim($text));
            $decoded = json_decode((string) $clean, true);
            if (is_array($decoded) && isset($decoded['executive_summary'])) {
                return $decoded + ['model' => $model, 'generated_at' => now()->toIso8601String()];
            }
            $lastErr = 'Format jawaban AI tidak sesuai.';
        }

        throw new RuntimeException($lastErr);
    }
}
