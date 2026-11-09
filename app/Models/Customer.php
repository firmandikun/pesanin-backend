<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = ['id', 'user_id'];

    protected $casts = ['labels' => 'array', 'opt_in' => 'boolean'];

    public function threads()
    {
        return $this->hasMany(ChatThread::class);
    }

    /** Digits only, Indonesian numbers normalised to 62… so chats and orders match. */
    public static function normalizePhone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        } elseif (str_starts_with($d, '8')) {
            $d = '62'.$d;
        }

        return $d;
    }

    /** Orders of this seller whose phone matches the customer (any common format). */
    public function orders()
    {
        $p = self::normalizePhone($this->phone);
        $q = Order::where('user_id', $this->user_id);
        if (! $p) {
            return $q->whereRaw('1 = 0');
        }
        $local = '0'.substr($p, 2);

        return $q->where(fn ($w) => $w->whereIn('phone', [$p, '+'.$p, $local])
            ->orWhereRaw("REPLACE(REPLACE(REPLACE(phone, '-', ''), ' ', ''), '+', '') IN (?, ?)", [$p, $local]));
    }
}
