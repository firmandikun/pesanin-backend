<?php

namespace App\Support;

use App\Models\TeamMember;
use Illuminate\Http\Request;

/**
 * Team member sessions. A member signs in with their own email + password but gets a Sanctum token
 * on the store owner's account (named "member:{id}"), so every store query keeps working unchanged;
 * this class tells who is acting and which API paths their role may use.
 */
class Member
{
    public const ROLES = ['admin' => 'Admin', 'cs' => 'Customer Service', 'advertiser' => 'Advertiser', 'gudang' => 'Gudang'];

    /** Owner-only: account, billing, keys/integrations, team management, connecting or removing channels. */
    private const OWNER_ONLY = [
        '#^PUT me(/password)?$#', '#^\w+ (billing|integrations|banned-devices|activity-logs|balance|marketplace/balance)(/|$)#',
        '#^\w+ r/(team|domains|notification-accounts)(/|$)#', '#^PUT user-settings$#',
        '#^(POST|DELETE) meta-ads(/(connect|token))?$#', '#^(POST|DELETE) meta-chat(/connect)?$#',
        '#^POST marketplace/shopee/(connect|complete|demo|share-link)$#', '#^DELETE marketplace/shops/#', '#^\w+ whatsapp/connect(/|$)#',
    ];

    /** Every member can load the app shell. */
    private const COMMON = [
        '#^GET me$#', '#^POST logout$#', '#^\w+ (notifications|push)(/|$)#', '#^GET (dashboard|followup-variables|categories|user-settings|addresses)(/|$)#',
        '#^POST (uploads(/digital)?|shipping/preview)$#',
    ];

    private const ROLE = [
        'cs' => [
            '#^\w+ (chat|chat-labels|quick-replies|customers|orders|labels)(/|$)#', '#^GET (products|inventory|rotator-chats|followup-templates)(/|$)#',
            '#^GET marketplace/(shops|listings)$#', '#^\w+ r/payment-confirmations(/|$)#', '#^GET r/(staff|coupons)$#',
        ],
        'gudang' => [
            '#^\w+ (orders|labels|products|inventory|transactions)(/|$)#', '#^\w+ marketplace/(shops|listings|listing-costs)(/|$)#',
            '#^GET r/staff$#',
        ],
        'advertiser' => [
            '#^\w+ (reports|research|landing-pages|landing-reports|product-reports|rotator-stats|affiliate-stats)(/|$)#',
            '#^(GET meta-ads(/report)?|PUT meta-ads/accounts|POST meta-ads/sync)$#', '#^GET marketplace/(ads|shops)$#',
            '#^\w+ r/(pixels|ad-spends|rotators|affiliates|coupons)(/|$)#', '#^GET (products|orders)(/|$)#',
            '#^\w+ (broadcasts|whatsapp/templates)(/|$)#', '#^GET (customers|chat-labels)(/|$)#',
        ],
    ];

    public static function current(Request $request): ?TeamMember
    {
        if ($request->attributes->has('member')) {
            return $request->attributes->get('member');
        }
        $name = $request->user()?->currentAccessToken()?->name ?? null;
        $member = is_string($name) && str_starts_with($name, 'member:')
            ? TeamMember::where('user_id', $request->user()->id)->find((int) substr($name, 7))
            : null;
        $request->attributes->set('member', $member);

        return $member;
    }

    public static function allows(string $role, string $method, string $path): bool
    {
        $line = strtoupper($method === 'HEAD' ? 'GET' : $method).' '.ltrim($path, '/');
        $match = fn (array $patterns) => collect($patterns)->contains(fn ($p) => preg_match($p, $line));
        if ($match(self::OWNER_ONLY)) {
            return false;
        }
        if ($role === 'admin' || $match(self::COMMON)) {
            return true;
        }

        return $match(self::ROLE[$role] ?? []);
    }

    /** Display name of whoever is acting: the member, or the owner. */
    public static function actorName(Request $request): string
    {
        return self::current($request)?->name ?? $request->user()->name;
    }
}
