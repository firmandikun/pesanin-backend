<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A shop's Shopee seller balance, straight from Shopee: the wallet balance (`current_balance` of the latest wallet
 * transaction) and the income overview (pending vs released). Cached briefly so the Saldo page stays fast.
 */
class ShopeeWallet
{
    public const LABELS = [
        'ESCROW_VERIFIED_ADD' => 'Penghasilan pesanan', 'ESCROW_VERIFIED_MINUS' => 'Potongan pesanan',
        'WITHDRAWAL_CREATED' => 'Penarikan dibuat', 'WITHDRAWAL_COMPLETED' => 'Penarikan selesai', 'WITHDRAWAL_CANCELLED' => 'Penarikan dibatalkan',
        'ADJUSTMENT_ADD' => 'Penyesuaian (+)', 'ADJUSTMENT_MINUS' => 'Penyesuaian (−)',
        'FBS_ADJUSTMENT_ADD' => 'Penyesuaian fulfillment (+)', 'FBS_ADJUSTMENT_MINUS' => 'Penyesuaian fulfillment (−)',
    ];

    public static function summary(MarketplaceShop $shop, bool $fresh = false): array
    {
        $key = "shopee-wallet:{$shop->id}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(2), fn () => self::load($shop));
    }

    private static function load(MarketplaceShop $shop): array
    {
        $out = ['shop_id' => $shop->id, 'shop_name' => $shop->shop_name, 'balance' => null, 'balance_at' => null,
            'pending' => null, 'released' => null, 'transactions' => [], 'error' => null, 'fetched_at' => now()->toIso8601String()];
        $api = new ShopeeClient;
        $errors = [];
        try {
            // Without a range Shopee returns the recent ones; if that window is empty, look further back 15 days at a time.
            $txns = $api->walletTransactions($shop, 20);
            for ($w = 0; ! $txns && $w < 6; $w++) {
                $to = now()->subDays(15 * $w);
                $txns = $api->walletTransactions($shop, 20, $to->copy()->subDays(15)->timestamp, $to->timestamp);
            }
            $txns = collect($txns)->sortByDesc('create_time')->values();
            if ($latest = $txns->first()) {
                $out['balance'] = (int) round((float) ($latest['current_balance'] ?? 0));
                $out['balance_at'] = ! empty($latest['create_time']) ? date(DATE_ATOM, (int) $latest['create_time']) : null;
            }
            $out['transactions'] = $txns->take(10)->map(fn ($t) => [
                'date' => ! empty($t['create_time']) ? date(DATE_ATOM, (int) $t['create_time']) : null,
                'type' => self::LABELS[$t['transaction_type'] ?? ''] ?? ($t['txn_title'] ?? null ?: ($t['transaction_type'] ?? '-')),
                'order_sn' => ($t['order_sn'] ?? '') ?: null,
                'amount' => (int) round((float) ($t['amount'] ?? 0)),
                'balance' => (int) round((float) ($t['current_balance'] ?? 0)),
                'status' => $t['status'] ?? null,
            ])->all();
        } catch (Throwable $e) {
            $errors[] = 'Saldo dompet: '.$e->getMessage();
        }
        try {
            $income = $api->incomeOverview($shop);
            $out['pending'] = isset($income['pending_amount']) ? (int) round((float) $income['pending_amount']) : null;
            $out['released'] = isset($income['released_amount']) ? (int) round((float) $income['released_amount']) : null;
        } catch (Throwable $e) {
            $errors[] = 'Ringkasan penghasilan: '.$e->getMessage();
        }
        $out['error'] = $errors ? implode(' · ', $errors) : null;

        return $out;
    }
}
