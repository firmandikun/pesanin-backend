<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatLabel extends Model
{
    protected $guarded = ['id', 'user_id'];

    /** Make sure a label typed on a chat or customer exists in the seller's label list. */
    public static function remember(int $userId, string $name): void
    {
        if (! static::where('user_id', $userId)->where('name', $name)->exists()) {
            static::forceCreate(['user_id' => $userId, 'name' => $name, 'color' => 'slate']);
        }
    }
}
