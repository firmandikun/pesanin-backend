<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Region extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['biteship_area_id', 'rajaongkir_id'];

    protected $appends = ['label'];

    public function getLabelAttribute(): string
    {
        return ($this->subdistrict ? "{$this->subdistrict}, " : '')."{$this->district}, {$this->city}, {$this->province}".($this->postal_code ? " {$this->postal_code}" : '');
    }
}
