<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CsStaff extends Model
{
    protected $table = 'cs_staff';

    protected $guarded = ['id', 'user_id'];

    protected $casts = ['is_active' => 'boolean', 'schedule' => 'array', 'last_assigned_at' => 'datetime'];

    /** Active staff that are on shift now and not on time off (WA Rotator & "WhatsApp acak" after checkout). */
    public static function onDuty(\Illuminate\Support\Collection $staff): \Illuminate\Support\Collection
    {
        $day = strtolower(now()->englishDayOfWeek);
        $time = now()->format('H:i');

        return $staff->filter(function (self $s) use ($day, $time) {
            if (! $s->is_active) {
                return false;
            }
            $off = \Illuminate\Support\Facades\DB::table('staff_time_offs')->where('cs_staff_id', $s->id)->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists();
            $shift = $s->schedule[$day] ?? null;
            $onShift = ! $s->schedule || ($shift && ! empty($shift['on']) && $time >= ($shift['start'] ?? '00:00') && $time <= ($shift['end'] ?? '23:59'));

            return ! $off && $onShift;
        })->values();
    }
}
