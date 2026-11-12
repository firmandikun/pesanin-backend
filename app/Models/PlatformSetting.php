<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** Cached read; null when unset (or before the table exists). */
    public static function read(string $key, mixed $default = null): mixed
    {
        $all = Cache::remember('platform_settings', 300, fn () => Schema::hasTable('platform_settings')
            ? self::query()->pluck('value', 'key')->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v)->all() : []);

        return $all[$key] ?? $default;
    }

    public static function write(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('platform_settings');
    }
}
