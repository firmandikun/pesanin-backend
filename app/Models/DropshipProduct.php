<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DropshipProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'featured' => 'boolean',
            'images' => 'array',
            'payment_methods' => 'array',
            'variants' => 'array',
            'videos' => 'array',
            'slides' => 'array',
            'lp_templates' => 'array',
            'weight_gram' => 'integer',
            'stock' => 'integer',
            'wholesale_price' => 'integer',
            'min_price' => 'integer',
            'suggested_price' => 'integer',
            'compare_price' => 'integer',
            'sold_30d' => 'integer',
        ];
    }

    public function adoptions(): HasMany
    {
        return $this->hasMany(DropshipAdoption::class);
    }
}
