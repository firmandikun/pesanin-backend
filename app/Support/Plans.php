<?php

namespace App\Support;

use App\Models\CustomDomain;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Subscription catalog, quota limits and feature gates. null = unlimited. */
class Plans
{
    public const TAX_PERCENT = 11;

    /**
     * Two tracks (GTM Playbook v2.0): sellers only pay for the way they sell.
     *  - marketplace: Shopee sellers (orders, shops, fees, profit per SKU)
     *  - social: Meta Ads / WhatsApp / IG / COD sellers (checkout links, landing pages, domains, pixels)
     * The other track can be added to a paid plan as an add-on at ADDON_DISCOUNT% off.
     */
    public const TRACKS = ['marketplace' => 'Pesanin Marketplace', 'social' => 'Pesanin Social & Web'];

    public const TRACK_SHORT = ['marketplace' => 'Marketplace', 'social' => 'Social & Web'];

    /** Yearly billing: this % off the monthly price. */
    public const YEARLY_DISCOUNT = 20;

    /** Second track as add-on: this % off its price. */
    public const ADDON_DISCOUNT = 30;

    /** Kept for the yearly toggle in older pages: 12 months at YEARLY_DISCOUNT% off. */
    public const YEARLY_MONTHS_PAID = 9.6;

    /** Price list and quotas per track. Enterprise is a custom contract (price per store, set by Pesanin). */
    public const CATALOG = [
        'marketplace' => [
            'gratis' => ['name' => 'Gratis', 'rank' => 0, 'monthly' => 0, 'limits' => ['orders' => 100, 'products' => 3, 'landing_pages' => 1, 'domains' => 0, 'team' => 1, 'shops' => 1, 'research' => 5, 'broadcast' => 100]],
            'starter' => ['name' => 'Starter', 'rank' => 1, 'monthly' => 79000, 'limits' => ['orders' => 1500, 'products' => 20, 'landing_pages' => 2, 'domains' => 0, 'team' => 2, 'shops' => 2, 'research' => 20, 'broadcast' => 500]],
            'bisnis' => ['name' => 'Bisnis', 'rank' => 2, 'monthly' => 169000, 'limits' => ['orders' => 10000, 'products' => 50, 'landing_pages' => 5, 'domains' => 1, 'team' => 5, 'shops' => 5, 'research' => 100, 'broadcast' => 2000]],
            'pro' => ['name' => 'Pro', 'rank' => 3, 'monthly' => 349000, 'limits' => ['orders' => 30000, 'products' => null, 'landing_pages' => 10, 'domains' => 3, 'team' => 20, 'shops' => 15, 'research' => 300, 'broadcast' => 10000]],
            'enterprise' => ['name' => 'Enterprise', 'rank' => 4, 'monthly' => 1500000, 'limits' => ['orders' => null, 'products' => null, 'landing_pages' => null, 'domains' => null, 'team' => null, 'shops' => null, 'research' => null, 'broadcast' => null]],
        ],
        'social' => [
            'gratis' => ['name' => 'Gratis', 'rank' => 0, 'monthly' => 0, 'limits' => ['orders' => 50, 'products' => 1, 'landing_pages' => 1, 'domains' => 0, 'team' => 1, 'shops' => 0, 'research' => 5, 'broadcast' => 100]],
            'starter' => ['name' => 'Starter', 'rank' => 1, 'monthly' => 99000, 'limits' => ['orders' => 1000, 'products' => 20, 'landing_pages' => 5, 'domains' => 1, 'team' => 2, 'shops' => 0, 'research' => 20, 'broadcast' => 2000]],
            'bisnis' => ['name' => 'Bisnis', 'rank' => 2, 'monthly' => 199000, 'limits' => ['orders' => 10000, 'products' => null, 'landing_pages' => null, 'domains' => 3, 'team' => 5, 'shops' => 0, 'research' => 100, 'broadcast' => 20000]],
            'pro' => ['name' => 'Pro', 'rank' => 3, 'monthly' => 399000, 'limits' => ['orders' => 30000, 'products' => null, 'landing_pages' => null, 'domains' => 10, 'team' => 20, 'shops' => 0, 'research' => 300, 'broadcast' => 100000]],
            'enterprise' => ['name' => 'Enterprise', 'rank' => 4, 'monthly' => 1500000, 'limits' => ['orders' => null, 'products' => null, 'landing_pages' => null, 'domains' => null, 'team' => null, 'shops' => null, 'research' => null, 'broadcast' => null]],
        ],
    ];

    /** Plan ids, ranks and quota keys (identical in both tracks). */
    public const PLANS = self::CATALOG['marketplace'];

    /** Plans a store can buy itself; Enterprise goes through sales. */
    public const SELF_SERVE = ['starter', 'bisnis', 'pro'];

    /** Lowest plan that unlocks a feature. */
    public const FEATURES = ['pixels' => 'starter', 'rotators' => 'starter', 'coupons' => 'bisnis', 'affiliates' => 'bisnis', 'stock_master' => 'starter', 'no_watermark' => 'bisnis'];

    /** Track a feature belongs to (also unlocked by the other track's add-on, and always by Enterprise). */
    public const FEATURE_TRACK = ['pixels' => 'social', 'rotators' => 'social', 'coupons' => 'social', 'affiliates' => 'social', 'no_watermark' => 'social', 'stock_master' => 'marketplace'];

    public const LABELS = [
        'orders' => 'pesanan bulan ini', 'products' => 'produk', 'landing_pages' => 'landing page', 'domains' => 'custom domain', 'team' => 'anggota tim', 'shops' => 'toko marketplace', 'research' => 'riset pasar hari ini', 'broadcast' => 'penerima broadcast bulan ini',
        'pixels' => 'Tracking pixel', 'rotators' => 'WhatsApp Rotator', 'coupons' => 'Kupon & order bump', 'affiliates' => 'Program afiliasi', 'stock_master' => 'Stok master lintas toko', 'no_watermark' => 'Tanpa watermark Pesanin',
    ];

    /** Every new store gets this plan free for TRIAL_DAYS. */
    public const TRIAL_PLAN = 'bisnis';

    public const TRIAL_DAYS = 7;

    /** A lapsed paid plan keeps working this many days before the store must pick a plan. */
    public const GRACE_DAYS = 7;

    public const METHODS = ['va_bca', 'va_bni', 'va_bri', 'va_mandiri', 'qris', 'dana', 'ovo', 'shopeepay'];

    public static function otherTrack(string $track): string
    {
        return $track === 'social' ? 'marketplace' : 'social';
    }

    /** Plan catalog of a track with the admin's changes (name, monthly price, quotas) applied. */
    public static function all(string $track = 'marketplace'): array
    {
        $track = isset(self::CATALOG[$track]) ? $track : 'marketplace';
        $over = \App\Models\PlatformSetting::read('plans_v2', [])[$track] ?? [];
        $plans = self::CATALOG[$track];
        foreach ($plans as $id => &$p) {
            $o = $over[$id] ?? [];
            if (isset($o['name']) && $o['name'] !== '') {
                $p['name'] = (string) $o['name'];
            }
            if (isset($o['monthly']) && $id !== 'gratis') {
                $p['monthly'] = max(0, (int) $o['monthly']);
            }
            foreach ($p['limits'] as $k => $v) {
                if (array_key_exists($k, $o['limits'] ?? [])) {
                    $p['limits'][$k] = $o['limits'][$k] === null ? null : max(0, (int) $o['limits'][$k]);
                }
            }
        }

        return $plans;
    }

    /** Lowest plan per gated feature, with the admin's changes applied over FEATURES. */
    public static function features(): array
    {
        $over = \App\Models\PlatformSetting::read('features', []);

        return collect(self::FEATURES)->map(fn ($need, $f) => isset(self::PLANS[$over[$f] ?? '']) ? $over[$f] : $need)->all();
    }

    public static function track(User $user): string
    {
        return isset(self::CATALOG[$user->plan_track ?? '']) ? $user->plan_track : 'marketplace';
    }

    /** Add-on of the other track is part of the plan (Enterprise always includes both). */
    public static function hasAddon(User $user): bool
    {
        $plan = self::current($user);

        return $plan === 'enterprise' || ($plan !== 'gratis' && (bool) $user->plan_addon);
    }

    /**
     * The plan as it applies to this store: its track's quotas, widened by the add-on (highest of both tracks),
     * Enterprise at its contract price. @return array{id: string, name: string, rank: int, monthly: int, limits: array, track: string, addon: bool}
     */
    public static function forUser(User $user): array
    {
        $id = self::current($user);
        $track = self::track($user);
        $p = self::all($track)[$id];
        $addon = self::hasAddon($user) && $id !== 'enterprise';
        if ($addon) {
            $o = self::all(self::otherTrack($track))[$id];
            foreach ($p['limits'] as $k => $v) {
                $w = $o['limits'][$k];
                $p['limits'][$k] = $v === null || $w === null ? null : max($v, $w);
            }
            $p['name'] .= ' + '.self::TRACK_SHORT[self::otherTrack($track)];
        }
        if ($id === 'enterprise' && $user->plan_price) {
            $p['monthly'] = (int) $user->plan_price;
        }

        return $p + ['id' => $id, 'track' => $track, 'addon' => $addon];
    }

    /** Active plan id. A lapsed paid plan stays in effect through its grace period, then falls back to Gratis limits. */
    public static function current(User $user): string
    {
        $plan = $user->plan ?: 'gratis';
        if (! isset(self::PLANS[$plan])) {
            return 'gratis';
        }

        return in_array(self::status($user)['status'], ['expired'], true) ? 'gratis' : $plan;
    }

    /**
     * Subscription state shown to the store and enforced by the "subscribed" middleware:
     *  - free: chose the Gratis plan (or never had a paid plan)
     *  - trial: inside the free trial
     *  - active: paid plan running
     *  - grace: paid plan ended less than GRACE_DAYS ago, everything still works
     *  - expired: trial or grace is over; the app is locked until a plan is chosen
     */
    public static function status(User $user): array
    {
        $plan = $user->plan ?: 'gratis';
        $ends = $user->plan_expires_at;
        $trial = $user->plan_billing === 'trial';
        $out = ['plan' => $plan, 'ends_at' => $ends?->toIso8601String(), 'days_left' => null, 'grace_ends_at' => null];

        if ($plan === 'gratis' || ! $ends) {
            return ['status' => 'free'] + $out;
        }
        if ($ends->isFuture()) {
            return ['status' => $trial ? 'trial' : 'active', 'days_left' => self::daysUntil($ends)] + $out;
        }
        $grace = $ends->copy()->addDays(self::GRACE_DAYS);
        if (! $trial && $grace->isFuture()) {
            return ['status' => 'grace', 'days_left' => self::daysUntil($grace), 'grace_ends_at' => $grace->toIso8601String()] + $out;
        }

        return ['status' => 'expired', 'reason' => $trial ? 'trial' : 'paid'] + $out;
    }

    /** Calendar days left; 0 = ends today. */
    private static function daysUntil(\DateTimeInterface $end): int
    {
        return (int) round(now()->startOfDay()->diffInDays(\Carbon\Carbon::instance($end)->startOfDay()));
    }

    /** Start the free trial on a new store. */
    public static function startTrial(User $user): void
    {
        $ends = now()->addDays(self::TRIAL_DAYS)->endOfDay();
        $user->forceFill(['plan' => self::TRIAL_PLAN, 'plan_billing' => 'trial', 'plan_expires_at' => $ends, 'trial_ends_at' => $ends, 'plan_auto_renew' => false])->save();
    }

    /** Monthly price of a plan, with the other track as add-on at ADDON_DISCOUNT% off. */
    public static function monthly(string $plan, string $track = 'marketplace', bool $addon = false): int
    {
        $m = self::all($track)[$plan]['monthly'] ?? 0;
        if ($addon && $plan !== 'gratis' && $plan !== 'enterprise') {
            $m += (int) (round(self::all(self::otherTrack($track))[$plan]['monthly'] * (100 - self::ADDON_DISCOUNT) / 100 / 1000) * 1000);
        }

        return $m;
    }

    /** Amount of one billing period. Yearly = 12 months at YEARLY_DISCOUNT% off (per-month price rounded down to Rp1.000). */
    public static function price(string $plan, string $billing, string $track = 'marketplace', bool $addon = false): int
    {
        $m = self::monthly($plan, $track, $addon);

        return $billing === 'yearly' ? (int) (floor($m * (100 - self::YEARLY_DISCOUNT) / 100 / 1000) * 1000 * 12) : $m;
    }

    public static function usage(User $user): array
    {
        return [
            'orders' => $user->orders()->where('created_at', '>=', now()->startOfMonth())->count(),
            'products' => $user->products()->count(),
            'landing_pages' => $user->landingPages()->count(),
            'domains' => CustomDomain::where('user_id', $user->id)->count(),
            'team' => TeamMember::where('user_id', $user->id)->count() + 1, // the owner counts as a seat
            'shops' => $user->marketplaceShops()->where('status', '!=', 'disconnected')->where('is_demo', false)->count(),
            'research' => self::researchUsed($user),
            'broadcast' => \App\Models\BroadcastRecipient::whereHas('broadcast', fn ($b) => $b->where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth()))->where('status', '!=', 'cancelled')->count(),
        ];
    }

    /** Abort with 402 when creating one more $quota would exceed the plan. */
    public static function ensureQuota(User $user, string $quota): void
    {
        $p = self::forUser($user);
        $limit = $p['limits'][$quota];
        if ($limit !== null && self::usage($user)[$quota] >= $limit) {
            $hint = $limit === 0 && $quota === 'shops'
                ? 'Toko marketplace tersedia di jalur '.self::TRACKS['marketplace'].'. Aktifkan add-on Marketplace di Paket & Tagihan.'
                : 'Upgrade paket untuk menambah lagi.';
            self::deny($limit === 0 ? 'Paket '.$p['name'].' belum termasuk '.self::LABELS[$quota].'. '.$hint : "Batas {$limit} ".self::LABELS[$quota].' di paket '.$p['name'].' sudah tercapai. '.$hint, $quota);
        }
    }

    public static function hasFeature(User $user, string $feature): bool
    {
        return self::featureBlock($user, $feature) === null;
    }

    public static function ensureFeature(User $user, string $feature): void
    {
        if ($msg = self::featureBlock($user, $feature)) {
            self::deny($msg, $feature);
        }
    }

    /** Why a feature is locked for this store (plan too low, or it belongs to the other track), or null. */
    public static function featureBlock(User $user, string $feature): ?string
    {
        $need = self::features()[$feature] ?? null;
        if (! $need) {
            return null;
        }
        $plan = self::current($user);
        $track = self::track($user);
        $ft = self::FEATURE_TRACK[$feature] ?? null;
        if ($ft && $ft !== $track && ! self::hasAddon($user)) {
            return self::LABELS[$feature].' ada di jalur '.self::TRACKS[$ft].'. Aktifkan add-on '.self::TRACK_SHORT[$ft].' (hemat '.self::ADDON_DISCOUNT.'%) di Paket & Tagihan.';
        }
        if (self::PLANS[$plan]['rank'] < self::PLANS[$need]['rank']) {
            return self::LABELS[$feature].' tersedia mulai paket '.self::all($track)[$need]['name'].'. Upgrade paket untuk memakainya.';
        }

        return null;
    }

    /** Riset Pasar searches today on Pesanin's shared keys (own keys are not counted). */
    public static function researchUsed(User $user): int
    {
        return (int) \Illuminate\Support\Facades\Cache::get('research:used:'.$user->id.':'.now()->toDateString(), 0);
    }

    public static function countResearch(User $user): void
    {
        $key = 'research:used:'.$user->id.':'.now()->toDateString();
        \Illuminate\Support\Facades\Cache::add($key, 0, now()->endOfDay());
        \Illuminate\Support\Facades\Cache::increment($key);
    }

    private static function deny(string $message, string $key): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'upgrade' => true, 'limit' => $key], 402));
    }
}
