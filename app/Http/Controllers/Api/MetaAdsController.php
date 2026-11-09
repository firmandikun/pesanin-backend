<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MetaAdInsight;
use App\Models\User;
use App\Models\UserIntegration;
use App\Support\Meta\MetaAds;
use App\Support\Meta\MetaAdsSync;
use App\Support\Traffic;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/** Iklan Meta: connect the seller's own Meta ad accounts (ads_read), sync daily spend, real ROAS from Pesanin orders. */
class MetaAdsController extends Controller
{
    public function status(Request $request)
    {
        $i = $this->integration($request);
        $accounts = [];
        $error = $i?->last_error;
        if ($api = MetaAds::for($i)) {
            try {
                $accounts = Cache::remember("meta:accounts:{$i->id}", 300, fn () => $api->adAccounts());
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return [
            'app_configured' => self::appConfigured(),
            'connected' => (bool) $i,
            'name' => $i?->settings['name'] ?? null,
            'token_expires_at' => $i?->settings['expires_at'] ?? null,
            'synced_at' => $i?->settings['synced_at'] ?? null,
            'selected' => ($i?->settings['account_ids'] ?? []) ?: null,
            'accounts' => $accounts,
            'error' => $error,
        ];
    }

    /** "Login dengan Facebook" URL (needs META_APP_ID/SECRET). */
    public function connect(Request $request)
    {
        abort_unless(self::appConfigured(), 422, 'Isi META_APP_ID dan META_APP_SECRET di backend/.env dulu, atau tempel access token.');
        $state = Crypt::encryptString(json_encode(['uid' => $request->user()->id, 'exp' => now()->addMinutes(15)->timestamp]));

        return ['url' => 'https://www.facebook.com/'.config('services.meta.graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'), 'redirect_uri' => config('services.meta.redirect'),
            'state' => $state, 'response_type' => 'code',
        ] + (config('services.meta.config_id')
            ? ['config_id' => config('services.meta.config_id'), 'override_default_response_type' => 'true']
            : ['scope' => 'ads_read']))];
    }

    public function callback(Request $request)
    {
        $front = rtrim(config('services.frontend_url'), '/').'/reports/meta-ads';
        try {
            $state = json_decode(Crypt::decryptString((string) $request->query('state')), true);
            abort_if(! $state || $state['exp'] < time() || ! $request->query('code'), 400);
            $res = Http::get(MetaAds::graph('oauth/access_token'), [
                'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
                'redirect_uri' => config('services.meta.redirect'), 'code' => $request->query('code'),
            ]);
            abort_unless($res->successful() && $res->json('access_token'), 400);
            // One redirect URI serves both Meta logins; the signed state says which one this is.
            if (($state['kind'] ?? 'ads') === 'whatsapp') {
                $r = WhatsAppConnectController::fromToken(User::findOrFail($state['uid']), $res->json('access_token'));

                return redirect(rtrim(config('services.frontend_url'), '/').'/settings/integrations?wa='.$r.'#whatsapp');
            }
            if (($state['kind'] ?? 'ads') === 'chat') {
                MetaChatController::saveFromToken(User::findOrFail($state['uid']), $res->json('access_token'));

                return redirect(rtrim(config('services.frontend_url'), '/').'/chat?meta=connected');
            }
            $this->save(User::findOrFail($state['uid']), $res->json('access_token'));

            return redirect($front.'?connected=1');
        } catch (Throwable $e) {
            report($e);

            $kind = json_decode(rescue(fn () => Crypt::decryptString((string) $request->query('state')), '{}', false), true)['kind'] ?? 'ads';

            $msg = $request->query('error_description') ?: ($e instanceof \RuntimeException ? $e->getMessage() : 'Gagal menghubungkan akun Meta.');
            if ($kind === 'whatsapp') {
                return redirect(rtrim(config('services.frontend_url'), '/').'/settings/integrations?wa_error='.urlencode($msg).'#whatsapp');
            }

            return redirect(($kind === 'chat' ? rtrim(config('services.frontend_url'), '/').'/chat' : $front).'?error='.urlencode($msg));
        }
    }

    /** Paste a token (Graph API Explorer / System User) — useful before the Meta app is set up. */
    public function token(Request $request)
    {
        $token = $request->validate(['access_token' => 'required|string|min:20|max:1000'])['access_token'];
        try {
            $i = $this->save($request->user(), trim($token));
        } catch (Throwable $e) {
            abort(422, $e->getMessage());
        }

        return ['name' => $i->settings['name'] ?? null];
    }

    public function accounts(Request $request)
    {
        $i = $this->integration($request);
        abort_unless($i, 404);
        $ids = $request->validate(['account_ids' => 'present|array', 'account_ids.*' => 'string|starts_with:act_'])['account_ids'];
        $i->forceFill(['settings' => array_merge($i->settings ?? [], ['account_ids' => array_values($ids)])])->save();

        return ['selected' => $ids];
    }

    public function sync(Request $request)
    {
        $i = $this->integration($request);
        abort_unless($i, 422, 'Hubungkan akun Meta dulu.');
        try {
            $rows = MetaAdsSync::run($i, (int) $request->input('days', 30));
        } catch (Throwable $e) {
            $i->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
            abort(422, $e->getMessage());
        }

        return ['rows' => $rows];
    }

    /** Also revokes Pesanin's access on Facebook, so the next login asks for permission again. */
    public function disconnect(Request $request)
    {
        $i = $this->integration($request);
        if ($i && $i->key('access_token')) {
            try {
                Http::timeout(15)->delete(MetaAds::graph('me/permissions'), ['access_token' => $i->key('access_token')]);
            } catch (Throwable) {
                // token already invalid: nothing left to revoke on Facebook
            }
        }
        $i?->delete();
        ActivityLog::record($request->user(), 'integration.disconnect', 'Memutuskan akun Meta Ads');

        return response()->noContent();
    }

    /** Spend & results per campaign next to the orders Pesanin actually received from it. */
    public function report(Request $request)
    {
        $d = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'account' => 'nullable|string']);
        $from = Carbon::parse($d['from'] ?? now()->subDays(29))->toDateString();
        $to = Carbon::parse($d['to'] ?? now())->toDateString();
        $uid = $request->user()->id;
        $base = MetaAdInsight::where('user_id', $uid)->whereBetween('date', [$from, $to])->when($d['account'] ?? null, fn ($q, $a) => $q->where('ad_account_id', $a));

        $campaigns = (clone $base)->selectRaw('campaign_id, MAX(campaign_name) as name, MAX(status) as status, MAX(objective) as objective, SUM(spend) as spend, SUM(impressions) as impressions, MAX(reach) as max_daily_reach, SUM(clicks) as clicks, SUM(results) as results, MAX(result_type) as result_type, SUM(purchases) as purchases, SUM(purchase_value) as purchase_value, SUM(messages) as messages')
            ->groupBy('campaign_id')->get();

        $reach = $this->periodReach($request, $from, $to, $d['account'] ?? null);

        // Orders that came through a Meta ad link, by utm_campaign (campaign name or {{campaign.id}}).
        $orders = $request->user()->orders()->whereIn('utm_source', Traffic::SOURCES['meta']['utm'])
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])->where('is_abandoned', false)->whereNotIn('status', ['cancelled', 'refunded'])
            ->selectRaw('LOWER(TRIM(utm_campaign)) as c, COUNT(*) as n, SUM(total) as revenue')->groupBy('c')->get()->keyBy('c');
        $matched = [];
        $rows = $campaigns->map(function ($c) use ($orders, $reach, &$matched) {
            $keys = array_filter([Str::lower(trim((string) $c->name)), (string) $c->campaign_id]);
            $hit = collect($keys)->map(fn ($k) => $orders[$k] ?? null)->filter();
            $matched = array_merge($matched, $hit->pluck('c')->all());
            $n = (int) $hit->sum('n');
            $rev = (int) $hit->sum('revenue');
            $spend = (int) $c->spend;
            $results = (int) $c->results;

            return [
                'id' => $c->campaign_id, 'name' => $c->name ?: $c->campaign_id, 'status' => $c->status, 'objective' => $c->objective,
                'spend' => $spend, 'impressions' => (int) $c->impressions, 'clicks' => (int) $c->clicks,
                'reach' => $reach['campaigns'][$c->campaign_id] ?? (int) $c->max_daily_reach,
                'results' => $results, 'result_type' => $c->result_type, 'result_label' => self::resultLabel($c->result_type),
                'cost_per_result' => $results ? (int) round($spend / $results) : null,
                'ctr' => $c->impressions ? round($c->clicks / $c->impressions * 100, 2) : 0,
                'meta_purchases' => (int) $c->purchases, 'meta_value' => (int) $c->purchase_value, 'messages' => (int) $c->messages,
                'orders' => $n, 'revenue' => $rev,
                'roas' => $spend ? round($rev / $spend, 2) : null,
                'cost_per_order' => $n ? (int) round($spend / $n) : null,
            ];
        })->sortByDesc('spend')->values();
        $unmatched = $orders->reject(fn ($o) => in_array($o->c, $matched, true));
        $spend = (int) $rows->sum('spend');
        $revenue = (int) $rows->sum('revenue');

        return [
            'from' => $from, 'to' => $to,
            'totals' => [
                'spend' => $spend, 'impressions' => (int) $rows->sum('impressions'), 'clicks' => (int) $rows->sum('clicks'),
                'reach' => $reach['total'] ?? (int) $rows->max('reach'), 'results' => (int) $rows->sum('results'),
                'result_label' => $rows->pluck('result_label')->filter()->unique()->count() === 1 ? $rows->pluck('result_label')->filter()->first() : null,
                'meta_purchases' => (int) $rows->sum('meta_purchases'), 'meta_value' => (int) $rows->sum('meta_value'), 'messages' => (int) $rows->sum('messages'),
                'orders' => (int) $rows->sum('orders'), 'revenue' => $revenue, 'roas' => $spend ? round($revenue / $spend, 2) : null,
            ],
            'unmatched' => ['orders' => (int) $unmatched->sum('n'), 'revenue' => (int) $unmatched->sum('revenue'), 'campaigns' => $unmatched->pluck('c')->filter()->take(10)->values()],
            'daily' => (clone $base)->selectRaw('date, SUM(spend) as spend')->groupBy('date')->orderBy('date')->get(),
            'campaigns' => $rows,
        ];
    }

    /** Unique reach for the whole period, per campaign and in total (live from Meta, cached 30 min). */
    private function periodReach(Request $request, string $from, string $to, ?string $account): array
    {
        $i = $this->integration($request);
        $api = MetaAds::for($i);
        if (! $api) {
            return [];
        }
        $accounts = MetaAdInsight::where('user_id', $request->user()->id)->when($account, fn ($q, $a) => $q->where('ad_account_id', $a))->distinct()->pluck('ad_account_id');

        return Cache::remember("meta:reach:{$i->id}:{$from}:{$to}:".($account ?? 'all'), 1800, function () use ($api, $accounts, $from, $to) {
            try {
                $out = ['campaigns' => [], 'total' => 0];
                foreach ($accounts as $acc) {
                    $out['campaigns'] += $api->periodReach($acc, $from, $to);
                    $out['total'] += $api->accountReach($acc, $from, $to);
                }

                return $out;
            } catch (Throwable) {
                return [];
            }
        });
    }

    /** Ads Manager wording for a `results` indicator. */
    public static function resultLabel(?string $indicator): ?string
    {
        if (! $indicator) {
            return null;
        }
        $key = preg_replace('/^actions:/', '', $indicator);

        return [
            'omni_landing_page_view' => 'Tayangan Halaman Landas', 'landing_page_view' => 'Tayangan Halaman Landas',
            'link_click' => 'Klik tautan', 'omni_purchase' => 'Pembelian', 'purchase' => 'Pembelian', 'offsite_conversion.fb_pixel_purchase' => 'Pembelian',
            'onsite_conversion.messaging_conversation_started_7d' => 'Percakapan dimulai', 'lead' => 'Prospek', 'onsite_conversion.lead_grouped' => 'Prospek',
            'post_engagement' => 'Interaksi postingan', 'page_engagement' => 'Interaksi Halaman', 'video_thruplay_watched_actions' => 'ThruPlay',
            'reach' => 'Jangkauan', 'impressions' => 'Impresi', 'omni_add_to_cart' => 'Tambah ke keranjang', 'omni_initiated_checkout' => 'Mulai checkout',
            'omni_complete_registration' => 'Pendaftaran selesai', 'like' => 'Suka Halaman',
        ][$key] ?? ucfirst(str_replace(['onsite_conversion.', 'offsite_conversion.', 'omni_', '_'], ['', '', '', ' '], $key));
    }

    private function save(User $user, string $token): UserIntegration
    {
        $expires = null;
        // Upgrade to a long-lived (~60 day) token when the app credentials are known.
        if (self::appConfigured()) {
            $res = Http::get(MetaAds::graph('oauth/access_token'), [
                'grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta.app_id'),
                'client_secret' => config('services.meta.app_secret'), 'fb_exchange_token' => $token,
            ]);
            if ($res->successful() && $res->json('access_token')) {
                $token = $res->json('access_token');
                $expires = $res->json('expires_in') ? now()->addSeconds((int) $res->json('expires_in'))->toIso8601String() : null;
            }
        }
        $api = new MetaAds($token);
        $me = $api->me(); // throws if the token is invalid
        $api->adAccounts(); // and if ads_read is missing

        $i = UserIntegration::firstOrNew(['user_id' => $user->id, 'provider' => 'meta_ads']);
        $i->forceFill([
            'credentials' => ['access_token' => $token], 'status' => 'active', 'is_production' => true, 'last_error' => null, 'verified_at' => now(),
            'webhook_token' => $i->webhook_token ?? Str::random(40),
            'settings' => array_merge($i->settings ?? [], ['name' => $me['name'] ?? null, 'fb_user_id' => $me['id'] ?? null, 'expires_at' => $expires]),
        ])->save();
        Cache::forget("meta:accounts:{$i->id}");
        ActivityLog::record($user, 'integration.connect', 'Menghubungkan akun Meta Ads '.($me['name'] ?? ''));

        // Pull the last 30 days right away so the report isn't empty after connecting.
        try {
            MetaAdsSync::run($i, 30);
        } catch (Throwable $e) {
            $i->update(['last_error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $i->refresh();
    }

    private function integration(Request $request): ?UserIntegration
    {
        return UserIntegration::where('user_id', $request->user()->id)->where('provider', 'meta_ads')->first();
    }

    private static function appConfigured(): bool
    {
        return (bool) (config('services.meta.app_id') && config('services.meta.app_secret'));
    }
}
