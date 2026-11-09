<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\Member;
use App\Support\DefaultTemplates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'store_name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'whatsapp' => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
        ]);
        $data['store_slug'] = $this->uniqueStoreSlug($data['store_name']);

        $user = User::create($data);
        DefaultTemplates::seed($user);
        ActivityLog::record($user, 'register', 'Akun dibuat');

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $data['email'])->first();

        if ($user && Hash::check($data['password'], $user->password)) {
            ActivityLog::record($user, 'login', 'Login ke dashboard');

            return ['token' => $user->createToken('web')->plainTextToken, 'user' => $user->toArray() + ['member' => null]];
        }

        // Team member: signs in to the owner's store with a token named after the member.
        $member = TeamMember::where('email', $data['email'])->where('is_active', true)->whereNotNull('password')->get()
            ->first(fn ($m) => Hash::check($data['password'], $m->password));
        if (! $member || ! $member->owner) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }
        $member->forceFill(['last_login_at' => now()])->save();
        ActivityLog::record($member->owner, 'login', "Login anggota tim: {$member->name}");

        return ['token' => $member->owner->createToken($member->tokenName())->plainTextToken, 'user' => $member->owner->toArray() + ['member' => $this->memberInfo($member)]];
    }

    public function me(Request $request)
    {
        $member = Member::current($request);

        return $request->user()->toArray() + ['member' => $member ? $this->memberInfo($member) : null];
    }

    private function memberInfo(TeamMember $m): array
    {
        return ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->role, 'role_label' => Member::ROLES[$m->role] ?? $m->role];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'store_name' => 'required|string|max:100',
            'store_slug' => ['required', 'alpha_dash', 'max:60', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'whatsapp' => 'nullable|string|max:30',
            'bank_accounts' => 'nullable|array|max:10',
            'bank_accounts.*.bank' => 'required|string|max:50',
            'bank_accounts.*.number' => 'required|string|max:40',
            'bank_accounts.*.holder' => 'required|string|max:100',
            'cod_fee_percent' => 'nullable|numeric|min:0|max:10',
        ]);
        $user->update($data);
        ActivityLog::record($user, 'profile.update', 'Memperbarui pengaturan akun');

        return $user;
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|current_password:sanctum',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $request->user()->update(['password' => $data['password']]);
        ActivityLog::record($request->user(), 'password.update', 'Mengganti password');

        return response()->noContent();
    }

    private function uniqueStoreSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'toko';
        $slug = $base;
        $i = 1;
        while (User::where('store_slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }
}
