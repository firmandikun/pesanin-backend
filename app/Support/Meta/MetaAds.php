<?php

namespace App\Support\Meta;

use App\Models\UserIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Meta Marketing API (Graph API) for a seller's own ad accounts. Read-only: ads_read.
 * Token comes from "Login dengan Facebook" (long-lived, ~60 days) or is pasted by the seller.
 */
class MetaAds
{
    /** Currencies whose Graph "minor unit" amounts are already whole units (no cents). */
    private const NO_DECIMALS = ['IDR', 'JPY', 'KRW', 'VND', 'CLP', 'COP', 'CRC', 'HUF', 'ISK', 'PYG', 'TWD'];

    public function __construct(private string $token) {}

    public static function for(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'meta_ads' && $i->key('access_token') ? new self($i->key('access_token')) : null;
    }

    public static function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version').($path ? '/'.ltrim($path, '/') : '');
    }

    public function me(): array
    {
        return $this->get('me', ['fields' => 'id,name']);
    }

    /** Ad accounts the token can read, amounts converted to whole currency units. */
    public function adAccounts(): array
    {
        return collect($this->paged('me/adaccounts', ['fields' => 'id,account_id,name,account_status,currency,timezone_name,amount_spent,spend_cap,balance,disable_reason,is_prepay_account,funding_source_details', 'limit' => 100]))
            ->map(fn ($a) => [
                'id' => $a['id'], 'name' => $a['name'] ?? $a['account_id'], 'currency' => $a['currency'] ?? 'IDR',
                'status' => (int) ($a['account_status'] ?? 0), // 1 active, 2 disabled, 3 unsettled, 7 pending review, 9 grace period, 101 closed
                'timezone' => $a['timezone_name'] ?? null,
                'amount_spent' => self::money($a['amount_spent'] ?? 0, $a['currency'] ?? 'IDR'),
                'spend_cap' => ($a['spend_cap'] ?? '0') !== '0' ? self::money($a['spend_cap'], $a['currency'] ?? 'IDR') : null,
                'balance' => self::money($a['balance'] ?? 0, $a['currency'] ?? 'IDR'), // amount owed, billed later
                // Prepaid accounts (common in Indonesia) stop delivering when this reaches 0.
                'prepaid' => (bool) ($a['is_prepay_account'] ?? false),
                'prepaid_balance' => ($a['is_prepay_account'] ?? false) ? self::prepaidBalance($a['funding_source_details']['display_string'] ?? '') : null,
            ])->values()->all();
    }

    public function campaigns(string $accountId): array
    {
        return collect($this->paged("{$accountId}/campaigns", ['fields' => 'id,name,effective_status,objective', 'limit' => 200]))
            ->keyBy('id')->all();
    }

    /** Daily insights per campaign between two dates (Y-m-d), max ~37 months back. */
    public function dailyCampaignInsights(string $accountId, string $since, string $until): array
    {
        return $this->paged("{$accountId}/insights", [
            'level' => 'campaign', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $since, 'until' => $until]),
            'fields' => 'campaign_id,campaign_name,spend,impressions,reach,clicks,actions,action_values,results',
            'limit' => 500,
        ]);
    }

    /** Reach over a whole period per campaign (daily reach can't be added up: people repeat across days). */
    public function periodReach(string $accountId, string $since, string $until): array
    {
        return collect($this->paged("{$accountId}/insights", [
            'level' => 'campaign', 'time_range' => json_encode(['since' => $since, 'until' => $until]), 'fields' => 'campaign_id,reach', 'limit' => 500,
        ]))->mapWithKeys(fn ($r) => [$r['campaign_id'] => (int) ($r['reach'] ?? 0)])->all();
    }

    public function accountReach(string $accountId, string $since, string $until): int
    {
        return (int) ($this->get("{$accountId}/insights", ['time_range' => json_encode(['since' => $since, 'until' => $until]), 'fields' => 'reach'])['data'][0]['reach'] ?? 0);
    }

    /** Ads Manager's "Hasil" column: [count, indicator] from the insights `results` field. */
    public static function result(array $row): array
    {
        $r = $row['results'][0] ?? null;

        return [(int) round((float) ($r['values'][0]['value'] ?? 0)), $r['indicator'] ?? null];
    }

    /** "Saldo yang Tersedia Rp125.000 IDR" → 125000. */
    private static function prepaidBalance(string $display): ?int
    {
        return preg_match('/([\d.,]+)/', $display, $m) ? (int) preg_replace('/[^\d]/', '', preg_replace('/[.,]\d{1,2}$/', '', $m[1])) : null;
    }

    /** Purchases & messaging conversations out of Meta's actions list. */
    public static function results(array $row): array
    {
        $find = function (array $list, array $types) {
            foreach ($types as $t) {
                foreach ($list as $a) {
                    if (($a['action_type'] ?? '') === $t) {
                        return (float) $a['value'];
                    }
                }
            }

            return 0;
        };
        $purchaseTypes = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'];

        return [
            'purchases' => (int) $find($row['actions'] ?? [], $purchaseTypes),
            'purchase_value' => (int) round($find($row['action_values'] ?? [], $purchaseTypes)),
            'messages' => (int) $find($row['actions'] ?? [], ['onsite_conversion.messaging_conversation_started_7d']),
        ];
    }

    public static function money($minor, string $currency): int
    {
        return (int) round(in_array(strtoupper($currency), self::NO_DECIMALS, true) ? (float) $minor : (float) $minor / 100);
    }

    private function paged(string $path, array $params): array
    {
        $out = [];
        $json = $this->get($path, $params);
        for ($i = 0; $i < 50; $i++) {
            array_push($out, ...($json['data'] ?? []));
            $next = $json['paging']['next'] ?? null;
            if (! $next) {
                break;
            }
            $json = $this->send(Http::timeout(60)->acceptJson()->get($next));
        }

        return $out;
    }

    private function get(string $path, array $params = []): array
    {
        return $this->send(Http::timeout(60)->acceptJson()->get(self::graph($path), $params + ['access_token' => $this->token]));
    }

    private function send($res): array
    {
        $json = $res->json() ?? [];
        if ($res->failed() || isset($json['error'])) {
            $e = $json['error'] ?? [];
            throw new RuntimeException(match ((int) ($e['code'] ?? 0)) {
                190 => 'Akses Meta kedaluwarsa atau dicabut. Hubungkan ulang akun Meta.',
                10, 200, 294 => 'Izin ads_read belum diberikan ke Pesanin untuk akun iklan ini.',
                4, 17, 613, 80004 => 'Batas permintaan Meta tercapai, coba lagi beberapa menit lagi.',
                default => 'Meta: '.($e['error_user_msg'] ?? $e['message'] ?? "HTTP {$res->status()}"),
            });
        }

        return $json;
    }
}
