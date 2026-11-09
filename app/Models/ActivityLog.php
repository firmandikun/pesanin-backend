<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function record(User $user, string $action, string $description): void
    {
        static::create([
            'user_id' => $user->id,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
        ]);
    }
}
