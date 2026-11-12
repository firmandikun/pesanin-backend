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

    /** Yearly billing = pay 10 months, get 12. */
    public const YEARLY_MONTHS_PAID = 10;

    public const PLANS = [
        'gratis' => ['name' => 'Gratis', 'rank' => 0, 'monthly' => 0, 'limits' => ['orders' => 100, 'products' => 3, 'landing_pages' => 1, 'domains' => 0, 'team' => 1, 'shops' => 1, 'research' => 5, 'broadcast' => 100]],
        'starter' => ['name' => 'Starter', 'rank' => 1, 'monthly' => 49000, 'limits' => ['orders' => 1000, 'products' => 20, 'landing_pages' => 5, 'domains' => 1, 'team' => 2, 'shops' => 2, 'research' => 20, 'broadcast' => 2000]],
        'bisnis' => ['name' => 'Bisnis', 'rank' => 2, 'monthly' => 129000, 'limits' => ['orders' => 10000, 'products' => null, 'landing_pages' => null, 'domains' => 3, 'team' => 5, 'shops' => 5, 'research' => 100, 'broadcast' => 20000]],
        'pro' => ['name' => 'Pro', 'rank' => 3, 'monthly' => 249000, 'limits' => ['orders' => null, 'products' => null, 'landing_pages' => null, 'domains' => 10, 'team' => 20, 'shops' => 20, 'research' => 300, 'broadcast' => 100000]],
    ];

    /** Lowest plan that unlocks a feature. */
    public const FEATURES = ['pixels' => 'starter', 'rotators' => 'bisnis', 'coupons' => 'bisnis', 'affiliates' => 'pro'];

    public const LABELS = [
        'orders' => 'pesanan bulan ini', 'products' => 'produk', 'landing_pages' => 'landing page', 'domains' => 'custom domain', 'team' => 'anggota tim', 'shops' => 'toko marketplace', 'research' => 'riset pasar hari ini', 'broadcast' => 'penerima broadcast bulan ini',
        'pixels' => 'Tracking pixel', 'rotators' => 'WhatsApp Rotator', 'coupons' => 'Kupon diskon', 'affiliates' => 'Program afiliasi',
    ];

    /** Every new store gets this plan free for TRIAL_DAYS. */
    public const TRIAL_PLAN = 'bisnis';

    public const TRIAL_DAYS = 7;

    /** A lapsed paid plan keeps working this many days before the store must pick a plan. */
    public const GRACE_DAYS = 7;

    public const METHODS = ['va_bca', 'va_bni', 'va_bri', 'va_mandiri', 'qris', 'dana', 'ovo', 'shopeepay'];

    /** Plan catalog with the admin's changes (name, monthly price, quotas) applied over PLANS. */
    public static function all(): array
    {
        $over = \App\Models\PlatformSetting::read('plans', []);
        $plans = self::PLANS;
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

    public static function price(string $plan, string $billing): int
    {
        $m = self::all()[$plan]['monthly'];

        return $billing === 'yearly' ? $m * self::YEARLY_MONTHS_PAID : $m;
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
        $plan = self::current($user);
        $limit = self::all()[$plan]['limits'][$quota];
        if ($limit !== null && self::usage($user)[$quota] >= $limit) {
            self::deny("Batas {$limit} ".self::LABELS[$quota].' di paket '.self::all()[$plan]['name'].' sudah tercapai. Upgrade paket untuk menambah lagi.', $quota);
        }
    }

    public static function ensureFeature(User $user, string $feature): void
    {
        $need = self::features()[$feature] ?? null;
        $plan = self::current($user);
        if ($need && self::all()[$plan]['rank'] < self::all()[$need]['rank']) {
            self::deny(self::LABELS[$feature].' tersedia mulai paket '.self::all()[$need]['name'].'. Upgrade paket untuk memakainya.', $feature);
        }
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
