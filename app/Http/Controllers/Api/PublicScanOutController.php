<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScanOutSession;
use App\Support\ScanOut;
use Illuminate\Http\Request;

/** The phone page opened from the dashboard QR: no login, the long random token is the access. */
class PublicScanOutController extends Controller
{
    private function session(string $token, bool $mustBeOpen = true): ScanOutSession
    {
        $s = ScanOutSession::where('token', $token)->with('user:id,name,store_name')->first();
        abort_unless($s, 404, 'Link scan tidak valid.');
        abort_if($mustBeOpen && ! $s->isOpen(), 410, 'Sesi scan sudah selesai atau kedaluwarsa. Buka QR baru dari dashboard.');

        return $s;
    }

    public function show(string $token)
    {
        $s = $this->session($token, false);
        $p = ScanOut::payload($s);
        $p['parcels'] = $p['parcels']->take(30)->values();

        return $p;
    }

    public function scan(Request $request, string $token)
    {
        $s = $this->session($token);
        // Several warehouse staff can scan with the same QR at once: each phone sends its staff name.
        $data = $request->validate(['code' => 'required|string|max:200', 'by' => 'nullable|string|max:60']);

        return ScanOut::record($s, $data['code'], self::staff($data['by'] ?? null)) + ['summary' => ScanOut::summary($s)];
    }

    /** Undo the last scan of this session (a wrong barcode on the label). */
    public function undo(string $token)
    {
        $s = $this->session($token);
        $last = $s->parcels()->latest('scanned_at')->latest('id')->first();
        $last?->delete();

        return ['removed' => $last?->code, 'summary' => ScanOut::summary($s)];
    }

    /** "Cek isi paket" done on the phone: products checked + photo of the open parcel. */
    public function pack(Request $request, string $token, int $order)
    {
        $s = $this->session($token);
        $o = \App\Models\Order::where('user_id', $s->user_id)->findOrFail($order);
        abort_unless($s->parcels()->where('order_id', $o->id)->exists(), 422, 'Scan resi pesanan ini dulu di sesi Scan Out.');
        $request->validate(\App\Support\Packing::RULES);

        return \App\Support\Packing::save($o, $request->file('photo'), $request->input('check'), self::staff($request->input('by')) ?? ($s->scanned_by ?: 'Scan HP'));
    }

    /** Staff name typed on the phone (plain text, trimmed). */
    private static function staff(mixed $by): ?string
    {
        $by = is_string($by) ? trim(preg_replace('/\s+/', ' ', strip_tags($by))) : '';

        return $by !== '' ? mb_substr($by, 0, 60) : null;
    }

    public function finish(string $token)
    {
        $s = $this->session($token, false);
        if ($s->status === 'open') {
            $s->update(['status' => 'closed', 'finished_at' => now()]);
        }

        return ['summary' => ScanOut::summary($s)];
    }
}
