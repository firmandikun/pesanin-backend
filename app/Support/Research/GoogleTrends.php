<?php

namespace App\Support\Research;

use App\Models\UserIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Trends through SerpApi (engine=google_trends). Each call costs one SerpApi search,
 * so results are cached for 12 hours. Values are Google's relative interest (0–100), not volumes.
 */
class GoogleTrends
{
    public const PERIODS = ['now 7-d' => '7 hari', 'today 1-m' => '30 hari', 'today 3-m' => '90 hari', 'today 12-m' => '12 bulan', 'today 5-y' => '5 tahun'];

    public function __construct(private UserIntegration $integration) {}

    /**
     * The seller's own account when connected, otherwise Pesanin's shared account from .env.
     * @return array{0: ?self, 1: 'own'|'pesanin'|null}
     */
    public static function resolve(\App\Models\User $user): array
    {
        if ($own = self::for($user->integration('serpapi'))) {
            return [$own, 'own'];
        }
        $shared = new UserIntegration(['provider' => 'serpapi', 'credentials' => ['api_key' => config('services.serpapi.key')], 'status' => 'active']);

        return ($c = self::for($shared)) ? [$c, 'pesanin'] : [null, null];
    }

    public static function for(?UserIntegration $i): ?self
    {
        return $i && $i->provider === 'serpapi' && $i->key('api_key') ? new self($i) : null;
    }

    /** Interest over time for up to 5 keywords, with a per-keyword summary. */
    public function interest(array $keywords, string $date = 'today 12-m', string $geo = 'ID'): array
    {
        $keywords = array_slice(array_values(array_filter(array_map('trim', $keywords))), 0, 5);
        $data = $this->search(['q' => implode(',', $keywords), 'data_type' => 'TIMESERIES', 'date' => $date, 'geo' => $geo]);
        $points = collect($data['interest_over_time']['timeline_data'] ?? [])->map(fn ($p) => [
            'date' => $p['date'] ?? '',
            'timestamp' => (int) ($p['timestamp'] ?? 0),
            'values' => collect($p['values'] ?? [])->mapWithKeys(fn ($v) => [$v['query'] => (int) ($v['extracted_value'] ?? 0)])->all(),
        ])->values();

        $summary = collect($keywords)->map(function ($k) use ($points) {
            $series = $points->map(fn ($p) => $p['values'][$k] ?? 0)->values();
            $n = $series->count();
            $q = max(1, (int) floor($n / 4));
            $first = $series->take($q)->avg() ?: 0;
            $last = $series->slice($n - $q)->avg() ?: 0;
            $peakIdx = $series->search($series->max());

            return [
                'keyword' => $k,
                'average' => (int) round($series->avg() ?: 0),
                'latest' => (int) ($series->last() ?? 0),
                'change' => $first > 0 ? (int) round(($last - $first) / $first * 100) : null,
                'peak' => $peakIdx !== false ? ($points[$peakIdx]['date'] ?? null) : null,
            ];
        })->all();

        return ['keywords' => $keywords, 'points' => $points->all(), 'summary' => $summary];
    }

    /** Rising and top related searches for one keyword. */
    public function related(string $keyword, string $date = 'today 12-m', string $geo = 'ID'): array
    {
        $data = $this->search(['q' => $keyword, 'data_type' => 'RELATED_QUERIES', 'date' => $date, 'geo' => $geo]);
        $map = fn ($rows) => collect($rows ?? [])->take(15)->map(fn ($r) => ['query' => $r['query'], 'value' => $r['value'] ?? null, 'score' => (int) ($r['extracted_value'] ?? 0)])->values()->all();

        return ['rising' => $map($data['related_queries']['rising'] ?? []), 'top' => $map($data['related_queries']['top'] ?? [])];
    }

    /** Interest by province for one keyword. */
    public function regions(string $keyword, string $date = 'today 12-m', string $geo = 'ID'): array
    {
        $data = $this->search(['q' => $keyword, 'data_type' => 'GEO_MAP_0', 'date' => $date, 'geo' => $geo, 'region' => 'REGION']);

        return collect($data['interest_by_region'] ?? [])->map(fn ($r) => ['region' => $r['location'] ?? '', 'value' => (int) ($r['extracted_value'] ?? 0)])
            ->filter(fn ($r) => $r['value'] > 0)->sortByDesc('value')->values()->all();
    }

    /** Uses SerpApi's free account endpoint (no search credit). */
    public function account(): array
    {
        $res = Http::acceptJson()->timeout(15)->get('https://serpapi.com/account.json', ['api_key' => $this->integration->key('api_key')]);
        if ($res->failed() || $res->json('error')) {
            throw new RuntimeException('SerpApi: '.($res->json('error') ?? "HTTP {$res->status()}"));
        }

        return ['plan' => $res->json('plan_name'), 'left' => $res->json('total_searches_left') ?? $res->json('plan_searches_left')];
    }

    private function search(array $params): array
    {
        $params += ['engine' => 'google_trends', 'hl' => 'id', 'tz' => -420];
        $key = 'research:trends:'.md5(json_encode($params));

        return Cache::remember($key, now()->addHours(12), function () use ($params) {
            $res = Http::acceptJson()->timeout(30)->get('https://serpapi.com/search.json', $params + ['api_key' => $this->integration->key('api_key')]);
            $json = $res->json() ?? [];
            if ($res->failed() || ! empty($json['error'])) {
                // "hasn't returned any results" just means too little search volume.
                if (str_contains((string) ($json['error'] ?? ''), "hasn't returned any results")) {
                    return [];
                }
                throw new RuntimeException('Google Trends: '.($json['error'] ?? "HTTP {$res->status()}"));
            }

            return $json;
        });
    }
}
