<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Order extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $hidden = ['checkout_token'];

    /** Shipped by the Pesanin warehouse (adopted dropship product): the seller does not ship it. */
    protected $appends = ['is_dropship'];

    public function getIsDropshipAttribute(): bool
    {
        return (bool) $this->dropship_product_id;
    }

    protected function casts(): array
    {
        return [
            'bump_selected' => 'boolean',
            'is_abandoned' => 'boolean',
            'shopee_auto_ship' => 'boolean',
            'escrow_final' => 'boolean',
            'fee_breakdown' => 'array',
            'tags' => 'array',
            'bump_items' => 'array',
            'custom_fields' => 'array',
            'items' => 'array',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
            'digital_sent_at' => 'datetime',
            'dropship_paid_at' => 'datetime',
            'over_quota' => 'boolean',
            'packed_at' => 'datetime',
            'packing_check' => 'array',
            'cs_contacted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->code ??= 'FK'.now()->format('ymd').strtoupper(Str::random(5));
        });
        // A dropship order that becomes paid goes to the warehouse queue (admins are notified once).
        static::saved(function (Order $order) {
            if ($order->dropship_product_id && ! $order->is_abandoned && $order->payment_status === 'paid'
                && ($order->wasChanged('payment_status') || ($order->wasRecentlyCreated && $order->payment_status === 'paid'))) {
                \App\Support\Dropship::notifyPaid($order);
            }
        });
        // Notify the seller the moment an order lands: new order, or a draft checkout completed.
        static::created(fn (Order $order) => \App\Support\Notifier::newOrder($order));
        static::updated(function (Order $order) {
            if ($order->wasChanged('is_abandoned') && ! $order->is_abandoned) {
                \App\Support\Notifier::newOrder($order);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dropshipProduct(): BelongsTo
    {
        return $this->belongsTo(DropshipProduct::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** CS penanggung jawab: confirms the order with the buyer (assigned in turn at checkout). */
    public function csStaff(): BelongsTo
    {
        return $this->belongsTo(CsStaff::class);
    }

    public function marketplaceShop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_abandoned', false)->whereNull('archived_at');
    }
}
