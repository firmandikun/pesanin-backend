<?php

namespace App\Http\Middleware;

use App\Support\Plans;
use Closure;
use Illuminate\Http\Request;

/**
 * Locks the seller app once the free trial (or a paid plan plus its grace period) is over,
 * until the owner pays or picks the Gratis plan. Account, sign-out and billing stay open.
 * Public storefront, checkout and webhooks are outside this group and keep working.
 */
class EnsureSubscribed
{
    private const OPEN = ['#^api/me(/|$)#', '#^api/logout$#', '#^api/billing(/|$)#'];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || $user->is_admin || Plans::status($user)['status'] !== 'expired') {
            return $next($request);
        }
        foreach (self::OPEN as $p) {
            if (preg_match($p, $request->path())) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Masa aktif paket sudah berakhir. Pilih paket untuk melanjutkan.',
            'subscription_required' => true,
        ], 402);
    }
}
