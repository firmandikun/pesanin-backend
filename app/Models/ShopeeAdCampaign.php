<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopeeAdCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['item_ids' => 'array'];
    }

    public function stats(): HasMany
    {
        return $this->hasMany(ShopeeAdStat::class);
    }
}
