<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Broadcast extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['variables' => 'array', 'audience' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function recipients()
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Adds per-status recipient counts to a query. */
    public function scopeWithStats($q)
    {
        return $q->withCount([
            'recipients as total',
            'recipients as queued' => fn ($r) => $r->where('status', 'queued'),
            'recipients as sent' => fn ($r) => $r->whereIn('status', ['sent', 'delivered', 'read']),
            'recipients as delivered' => fn ($r) => $r->whereIn('status', ['delivered', 'read']),
            'recipients as read' => fn ($r) => $r->where('status', 'read'),
            'recipients as failed' => fn ($r) => $r->where('status', 'failed'),
        ]);
    }

    /** Body with {{n}} filled for one recipient. */
    public function render(?string $name): string
    {
        return preg_replace_callback('/\{\{(\d+)\}\}/', fn ($m) => $this->value((int) $m[1], $name), (string) $this->body);
    }

    public function value(int $n, ?string $name): string
    {
        $v = $this->variables[$n - 1] ?? ['mode' => 'text', 'value' => ''];

        return ($v['mode'] ?? 'text') === 'name' ? (trim((string) $name) ?: ($v['value'] ?? 'Kak')) : (string) ($v['value'] ?? '');
    }
}
