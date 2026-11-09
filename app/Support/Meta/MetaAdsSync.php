<?php

namespace App\Support\Meta;

use App\Models\AdSpend;
use App\Models\MetaAdInsight;
use App\Models\UserIntegration;
use Illuminate\Support\Carbon;

/**
 * Pulls daily campaign insights for the seller's selected ad accounts, stores them, and mirrors the
 * spend into ad_spends (platform meta, synced) so the existing profit reports include it automatically.
 */
class MetaAdsSync
{
    public static function run(UserIntegration $integration, int $days = 30): int
    {
        $api = MetaAds::for($integration);
        if (! $api) {
            return 0;
        }
        $accounts = collect($api->adAccounts());
        // No selection (or an empty one) means every account that isn't closed.
        $selected = $integration->settings['account_ids'] ?? [];
        $accounts = $accounts->filter(fn ($a) => $selected ? in_array($a['id'], $selected, true) : $a['status'] !== 101);
        $since = now()->subDays(max(1, min($days, 90)) - 1)->toDateString();
        $until = now()->toDateString();
        $rows = 0;

        foreach ($accounts as $acc) {
            $campaigns = $api->campaigns($acc['id']);
            foreach ($api->dailyCampaignInsights($acc['id'], $since, $until) as $r) {
                $c = $campaigns[$r['campaign_id']] ?? [];
                $spend = (int) round((float) ($r['spend'] ?? 0)); // insights spend is already in whole units
                MetaAdInsight::updateOrCreate(
                    ['user_id' => $integration->user_id, 'campaign_id' => $r['campaign_id'], 'date' => $r['date_start']],
                    ['ad_account_id' => $acc['id'], 'campaign_name' => $r['campaign_name'] ?? null, 'status' => $c['effective_status'] ?? null, 'objective' => $c['objective'] ?? null,
                        'spend' => $spend, 'impressions' => (int) ($r['impressions'] ?? 0), 'reach' => (int) ($r['reach'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0),
                        'results' => MetaAds::result($r)[0], 'result_type' => MetaAds::result($r)[1]] + MetaAds::results($r),
                );
                AdSpend::updateOrCreate(
                    ['user_id' => $integration->user_id, 'platform' => 'meta', 'external_id' => $r['campaign_id'], 'spent_on' => Carbon::parse($r['date_start'])->toDateString(), 'synced' => true],
                    ['amount' => $spend, 'campaign' => $r['campaign_name'] ?? $r['campaign_id']],
                );
                $rows++;
            }
        }
        $integration->forceFill(['last_error' => null, 'verified_at' => now(), 'settings' => array_merge($integration->settings ?? [], ['synced_at' => now()->toIso8601String()])])->save();

        return $rows;
    }
}
