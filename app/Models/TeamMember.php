<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamMember extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $hidden = ['password'];

    protected $appends = ['can_login'];

    protected $casts = ['accept_orders' => 'boolean', 'landing_access' => 'boolean', 'is_active' => 'boolean', 'password' => 'hashed', 'last_login_at' => 'datetime'];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getCanLoginAttribute(): bool
    {
        return $this->password !== null && $this->email !== null;
    }

    /** Sanctum token name used for this member's sessions (tokens belong to the store owner). */
    public function tokenName(): string
    {
        return "member:{$this->id}";
    }

    public function revokeSessions(): void
    {
        \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $this->user_id)
            ->where('name', $this->tokenName())->delete();
    }
}
