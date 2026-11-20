<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScanOutParcel;
use App\Models\ScanOutSession;
use App\Support\Member;
use App\Support\ScanOut;
use Illuminate\Http\Request;

/**
 * Scan Out from the dashboard: open a handover session (its QR opens the phone camera page), watch the parcels
 * come in, scan with a USB scanner / type a resi, finish, and read back the history for the PDF proof.
 */
class ScanOutController extends Controller
{
    public function index(Request $request)
    {
        $sessions = ScanOutSession::where('user_id', $request->user()->id)->withCount('parcels')
            ->latest('started_at')->paginate(15);
        $sessions->getCollection()->transform(fn ($s) => $s->only(['id', 'status', 'scanned_by', 'parcels_count']) + [
            'started_at' => $s->started_at?->toIso8601String(), 'finished_at' => $s->finished_at?->toIso8601String(), 'open' => $s->isOpen(),
        ]);

        return $sessions;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        // One open session at a time: reuse it instead of scattering scans over several QR codes.
        $open = ScanOutSession::where('user_id', $user->id)->where('status', 'open')->where('expires_at', '>', now())->latest('id')->first();

        // Pull the resi of freshly arranged Shopee orders now, so the first scans already know their shop.
        $uid = $user->id;
        dispatch(fn () => ScanOut::refreshTracking(\App\Models\User::find($uid)))->afterResponse();

        return ScanOut::payload($open ?? ScanOut::open($user, Member::actorName($request)), true);
    }

    public function show(Request $request, ScanOutSession $session)
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        return ScanOut::payload($session, $session->isOpen());
    }

    public function scan(Request $request, ScanOutSession $session)
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        abort_unless($session->isOpen(), 422, 'Sesi scan sudah selesai.');
        $data = $request->validate(['code' => 'required|string|max:200']);

        return ScanOut::record($session, $data['code'], Member::actorName($request)) + ['summary' => ScanOut::summary($session)];
    }

    public function finish(Request $request, ScanOutSession $session)
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        if ($session->status === 'open') {
            $session->update(['status' => 'closed', 'finished_at' => now()]);
        }

        return ScanOut::payload($session);
    }

    public function destroyParcel(Request $request, ScanOutSession $session, ScanOutParcel $parcel)
    {
        abort_unless($session->user_id === $request->user()->id && $parcel->scan_out_session_id === $session->id, 404);
        abort_unless($session->isOpen(), 422, 'Sesi sudah selesai, data tidak bisa diubah.');
        $parcel->delete();

        return ['summary' => ScanOut::summary($session)];
    }
}
