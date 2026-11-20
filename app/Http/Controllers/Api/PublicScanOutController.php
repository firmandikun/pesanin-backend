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
        $data = $request->validate(['code' => 'required|string|max:200']);

        return ScanOut::record($s, $data['code']) + ['summary' => ScanOut::summary($s)];
    }

    /** Undo the last scan of this session (a wrong barcode on the label). */
    public function undo(string $token)
    {
        $s = $this->session($token);
        $last = $s->parcels()->latest('scanned_at')->latest('id')->first();
        $last?->delete();

        return ['removed' => $last?->code, 'summary' => ScanOut::summary($s)];
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
