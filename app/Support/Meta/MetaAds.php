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

    /** Check whether the current access token has a granted permission (e.g. ads_management). */
    public function hasPermission(string $permission): bool
    {
        $rows = $this->get('me/permissions')['data'] ?? [];
        foreach ($rows as $row) {
            if (($row['permission'] ?? '') === $permission && ($row['status'] ?? '') === 'granted') {
                return true;
            }
        }

        return false;
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

    /** Live campaigns with budget & status for management UI. */
    public function listCampaigns(string $accountId, string $currency = 'IDR'): array
    {
        return collect($this->paged("{$accountId}/campaigns", [
            'fields' => 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,created_time',
            'limit' => 100,
        ]))->map(fn ($c) => [
            'id' => $c['id'],
            'account_id' => $accountId,
            'name' => $c['name'] ?? $c['id'],
            'status' => $c['status'] ?? null,
            'effective_status' => $c['effective_status'] ?? ($c['status'] ?? null),
            'objective' => $c['objective'] ?? null,
            'daily_budget' => isset($c['daily_budget']) ? self::money($c['daily_budget'], $currency) : null,
            'lifetime_budget' => isset($c['lifetime_budget']) ? self::money($c['lifetime_budget'], $currency) : null,
            'created_time' => $c['created_time'] ?? null,
        ])->values()->all();
    }

    /** Facebook Pages the token can advertise on behalf of. */
    public function pages(): array
    {
        return collect($this->get('me/accounts', ['fields' => 'id,name', 'limit' => 100])['data'] ?? [])
            ->map(fn ($p) => ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? $p['id'])])
            ->values()->all();
    }

    /** Create a campaign (CBO daily budget) on an ad account. */
    public function createCampaign(string $accountId, string $name, string $objective, int $dailyBudget, string $currency = 'IDR', string $status = 'PAUSED'): array
    {
        return $this->post("{$accountId}/campaigns", [
            'name' => $name,
            'objective' => $objective,
            'status' => $status,
            'special_ad_categories' => json_encode([]),
            'daily_budget' => self::toMinor($dailyBudget, $currency),
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
        ]);
    }

    /** Create an Ad Set inside a campaign with targeting, schedule, and placements. */
    public function createAdSet(string $accountId, string $campaignId, string $name, string $optimizationGoal = 'LINK_CLICKS', string $status = 'PAUSED', array $opts = []): array
    {
        $targeting = [
            'geo_locations' => ['countries' => ['ID']],
            'age_min' => max(18, min(65, (int) ($opts['age_min'] ?? 18))),
            'age_max' => max(18, min(65, (int) ($opts['age_max'] ?? 65))),
        ];
        if (! empty($opts['gender']) && in_array((int) $opts['gender'], [1, 2], true)) {
            $targeting['genders'] = [(int) $opts['gender']];
        }
        if (($opts['placement'] ?? 'auto') === 'facebook') {
            $targeting['publisher_platforms'] = ['facebook'];
        } elseif (($opts['placement'] ?? 'auto') === 'instagram') {
            $targeting['publisher_platforms'] = ['instagram'];
        }

        $payload = [
            'name' => $name,
            'campaign_id' => $campaignId,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $optimizationGoal,
            'targeting' => json_encode($targeting),
            'status' => $status,
        ];
        if (! empty($opts['start_time'])) {
            $payload['start_time'] = $opts['start_time'];
        }
        if (! empty($opts['end_time'])) {
            $payload['end_time'] = $opts['end_time'];
        }

        return $this->post("{$accountId}/adsets", $payload);
    }

    /** Upload an image file to the ad account and return its Meta image_hash. */
    public function uploadAdImage(string $accountId, string $filePath, string $filename): string
    {
        $res = $this->send(
            Http::timeout(60)->acceptJson()
                ->attach('filename', file_get_contents($filePath), $filename)
                ->post(self::graph("{$accountId}/adimages"), ['access_token' => $this->token])
        );
        $images = $res['images'] ?? [];
        $first = reset($images);
        if (! is_array($first) || empty($first['hash'])) {
            throw new RuntimeException('Meta: gagal mengunggah gambar iklan.');
        }

        return (string) $first['hash'];
    }

    /** Create a Link Ad Creative + Ad under an Ad Set. */
    public function createLinkAd(string $accountId, string $adSetId, string $name, string $pageId, string $linkUrl, string $message, string $headline = '', string $status = 'PAUSED', array $opts = []): array
    {
        $linkData = array_filter([
            'link' => $linkUrl,
            'message' => $message,
            'name' => $headline ?: null,
            'description' => ! empty($opts['description']) ? $opts['description'] : null,
            'image_hash' => ! empty($opts['image_hash']) ? $opts['image_hash'] : null,
            'call_to_action' => ['type' => $opts['cta'] ?? 'SHOP_NOW', 'value' => ['link' => $linkUrl]],
        ]);
        $creative = $this->post("{$accountId}/adcreatives", [
            'name' => $name.' Creative',
            'object_story_spec' => json_encode([
                'page_id' => $pageId,
                'link_data' => $linkData,
            ]),
        ]);

        return $this->post("{$accountId}/ads", [
            'name' => $name,
            'adset_id' => $adSetId,
            'creative' => json_encode(['creative_id' => $creative['id']]),
            'status' => $status,
        ]);
    }

    /** Update campaign status (ACTIVE/PAUSED/ARCHIVED), name, or daily_budget. */
    public function updateCampaign(string $campaignId, array $fields, string $currency = 'IDR'): array
    {
        $payload = [];
        if (isset($fields['status'])) {
            $payload['status'] = $fields['status'];
        }
        if (isset($fields['name'])) {
            $payload['name'] = $fields['name'];
        }
        if (isset($fields['daily_budget'])) {
            $payload['daily_budget'] = self::toMinor((int) $fields['daily_budget'], $currency);
        }

        return $this->post($campaignId, $payload);
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

    public static function toMinor(int $amount, string $currency): int
    {
        return in_array(strtoupper($currency), self::NO_DECIMALS, true) ? $amount : $amount * 100;
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

    private function post(string $path, array $params = []): array
    {
        return $this->send(Http::timeout(60)->acceptJson()->asForm()->post(self::graph($path), $params + ['access_token' => $this->token]));
    }

    private function send($res): array
    {
        $json = $res->json() ?? [];
        if ($res->failed() || isset($json['error'])) {
            $e = $json['error'] ?? [];
            throw new RuntimeException(match ((int) ($e['code'] ?? 0)) {
                190 => 'Akses Meta kedaluwarsa atau dicabut. Hubungkan ulang akun Meta.',
                10, 200, 294 => 'Izin Meta belum cukup (pastikan token punya ads_read & ads_management untuk akun iklan ini).',
                4, 17, 613, 80004 => 'Batas permintaan Meta tercapai, coba lagi beberapa menit lagi.',
                100 => str_contains((string) ($e['message'] ?? ''), 'missing permissions')
                    ? 'Akses ditolak Meta: token belum punya izin ads_management atau akun iklan ini hanya bersifat baca (tidak bisa dibuatkan iklan lewat API).'
                    : 'Meta: '.($e['error_user_msg'] ?? $e['message'] ?? "HTTP {$res->status()}"),
                default => 'Meta: '.($e['error_user_msg'] ?? $e['message'] ?? "HTTP {$res->status()}"),
            });
        }

        return $json;
    }
}
