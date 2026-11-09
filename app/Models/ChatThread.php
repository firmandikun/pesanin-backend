<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThread extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['labels' => 'array', 'done_at' => 'datetime'];

    public function notes()
    {
        return $this->hasMany(ChatNote::class)->latest();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignee()
    {
        return $this->belongsTo(TeamMember::class, 'assigned_to');
    }
}
