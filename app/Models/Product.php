<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $guarded = ['id', 'user_id', 'views'];

    protected function casts(): array
    {
        return [
            'is_preorder' => 'boolean',
            'track_stock' => 'boolean',
            'allow_oversell' => 'boolean',
            'autoslide' => 'boolean',
            'whatsapp_validator' => 'boolean',
            'captcha' => 'boolean',
            'wholesale_prices' => 'array',
            'images' => 'array',
            'features' => 'array',
            'variants' => 'array',
            'variant_options' => 'array',
            'bump' => 'array',
            'digital_content' => 'array',
            'payment_methods' => 'array',
            'form_settings' => 'array',
            'success_settings' => 'array',
            'pixels' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Selling price: "Harga Diskon" when set, otherwise "Harga Normal". */
    public function sellingPrice(): int
    {
        return (int) ($this->sale_price ?: $this->price);
    }

    /** Unit price for a variant + quantity, honoring wholesale tiers. */
    public function unitPrice(?string $variant, int $qty): int
    {
        $price = $this->sellingPrice();
        foreach ($this->variants ?? [] as $v) {
            if (($v['name'] ?? null) === $variant && ! empty($v['price'])) {
                $price = (int) $v['price'];
            }
        }
        $tiers = collect($this->wholesale_prices ?? [])->sortByDesc('min_qty');
        foreach ($tiers as $tier) {
            if ($qty >= (int) $tier['min_qty']) {
                return min($price, (int) $tier['price']);
            }
        }

        return $price;
    }

    public function availableStock(?string $variant): ?int
    {
        if (! $this->track_stock) {
            return null;
        }
        foreach ($this->variants ?? [] as $v) {
            if (($v['name'] ?? null) === $variant && isset($v['stock'])) {
                return (int) $v['stock'];
            }
        }

        return $this->stock;
    }
}
