<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUSES = ['draft' => 'Draf', 'ordered' => 'Dipesan', 'received' => 'Diterima', 'cancelled' => 'Dibatalkan'];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['ordered_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'received_at' => 'datetime',
            'subtotal' => 'integer', 'discount' => 'integer', 'shipping_cost' => 'integer', 'total' => 'integer', 'paid' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class)->orderBy('paid_on');
    }

    /** What is still owed to the supplier (cancelled orders owe nothing). */
    public function getBalanceAttribute(): int
    {
        return $this->status === 'cancelled' ? 0 : max(0, $this->total - $this->paid);
    }

    public function getPaymentStatusAttribute(): string
    {
        return $this->paid <= 0 ? 'unpaid' : ($this->paid >= $this->total ? 'paid' : 'partial');
    }
}
