<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DropshipAdoption extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'selling_price' => 'integer',
            'compare_price' => 'integer',
            'payment_methods' => 'array',
            'lp_page' => 'array',
        ];
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
        return $this->belongsTo(Product::class);
    }

    public function landingPage(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class);
    }
}
