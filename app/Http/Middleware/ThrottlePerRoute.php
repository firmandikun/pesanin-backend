<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` per route. Laravel keys the limit by user only, so every throttled route shared one counter: after
 * a page fired a few throttled requests (sync, guard, promotions…), a route allowed 10/min (e.g. "Analisa dengan AI")
 * already answered "Too Many Attempts". Here each route (method + URI pattern) has its own counter per user / IP.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        return sha1(parent::resolveRequestSignature($request).'|'.$request->method().'|'.($route?->uri() ?? $request->path()));
    }
}
