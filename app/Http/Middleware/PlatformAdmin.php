<?php

namespace App\Http\Middleware;

use App\Support\Member;
use Closure;
use Illuminate\Http\Request;

/** Pesanin's own staff (users.is_admin); team-member sessions never get admin rights. */
class PlatformAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->is_admin && ! Member::current($request), 403, 'Khusus admin Pesanin.');

        return $next($request);
    }
}
