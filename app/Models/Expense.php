<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    /** category => [label, cuts profit?]. Ads live in AdSpend (synced from Shopee or entered by hand). */
    public const CATEGORIES = [
        'operational' => ['Biaya Operasional', true],
        'inventory' => ['HPP & Pembelian Stok', false],
        'asset' => ['Aset & Pembiayaan', false],
    ];

    public const PAYMENT_METHODS = ['cash' => 'Tunai', 'transfer' => 'Transfer Bank', 'credit_card' => 'Kartu Kredit', 'debit_card' => 'Kartu Debit', 'ewallet' => 'E-Wallet', 'tempo' => 'Tempo', 'other' => 'Lainnya'];

    protected $guarded = ['id', 'user_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d', 'amount' => 'integer'];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShop::class, 'marketplace_shop_id');
    }
}
