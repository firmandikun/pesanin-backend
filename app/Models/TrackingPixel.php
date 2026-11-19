<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackingPixel extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['server_side' => 'boolean', 'all_pages' => 'boolean', 'capi_token' => 'encrypted', 'capi_last_at' => 'datetime'];

    /** The Conversions API token never leaves the server. */
    protected $hidden = ['capi_token'];

    protected $appends = ['has_capi_token'];

    public function getHasCapiTokenAttribute(): bool
    {
        return filled($this->capi_token);
    }

    /** Platforms Pesanin actually installs on public pages. */
    public const PLATFORMS = ['facebook', 'tiktok', 'google_ads', 'gtm'];

    /**
     * The seller's saved pixels marked "Semua halaman" (while their plan includes Tracking pixel), grouped per platform, for the public checkout and landing pages.
     *
     * @return array<string, string[]>
     */
    public static function globalFor(User $user): array
    {
        $rows = ! \App\Support\Plans::hasFeature($user, 'pixels') ? collect() : self::where('user_id', $user->id)->where('all_pages', true)->whereIn('platform', self::PLATFORMS)->get(['platform', 'pixel_id']);

        return collect(self::PLATFORMS)->mapWithKeys(fn ($p) => [$p => $rows->where('platform', $p)->pluck('pixel_id')->unique()->values()->all()])->all();
    }
}
