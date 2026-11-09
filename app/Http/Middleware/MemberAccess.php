<?php

namespace App\Http\Middleware;

use App\Support\Member;
use Closure;
use Illuminate\Http\Request;

/** Limits team member sessions to the API paths their role allows; inactive members are signed out. */
class MemberAccess
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->user()?->currentAccessToken();
        if (! is_string($token?->name ?? null) || ! str_starts_with($token->name, 'member:')) {
            return $next($request);
        }
        $member = Member::current($request);
        if (! $member || ! $member->is_active || ! $member->can_login) {
            $token->delete();
            abort(401, 'Akses anggota tim sudah dinonaktifkan.');
        }
        $path = preg_replace('#^api/#', '', $request->path());
        abort_unless(Member::allows($member->role, $request->method(), $path), 403, 'Peran '.(Member::ROLES[$member->role] ?? $member->role).' tidak punya akses ke fitur ini.');

        return $next($request);
    }
}
