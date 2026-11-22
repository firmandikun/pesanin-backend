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
        $sessions = ScanOutSession::where('user_id', $request->user()->id)->where('started_at', '>=', now()->subDays(\App\Support\ScanOut::KEEP_DAYS))->withCount('parcels')
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

    /** "Cek isi paket" done on the dashboard: products checked + photo of the open parcel. */
    public function pack(Request $request, ScanOutSession $session, int $order)
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        $o = \App\Models\Order::where('user_id', $session->user_id)->findOrFail($order);
        abort_unless($session->parcels()->where('order_id', $o->id)->exists(), 422, 'Scan resi pesanan ini dulu di sesi Scan Out.');
        $request->validate(\App\Support\Packing::RULES);

        return \App\Support\Packing::save($o, $request->file('photo'), $request->input('check'), Member::actorName($request));
    }

    public function finish(Request $request, ScanOutSession $session)
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        if ($session->status === 'open') {
            $session->update(['status' => 'closed', 'finished_at' => now()]);
        }

        return ScanOut::payload($session);
    }

    /** "Cocokkan ulang toko": pull the latest resi from Shopee now and re-match the unknown parcels. */
    public function rematch(Request $request, ScanOutSession $session)
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        $stats = ScanOut::refreshTracking($request->user(), true);

        return ScanOut::payload($session, $session->isOpen()) + ['refresh' => $stats];
    }

    public function destroyParcel(Request $request, ScanOutSession $session, ScanOutParcel $parcel)
    {
        abort_unless($session->user_id === $request->user()->id && $parcel->scan_out_session_id === $session->id, 404);
        abort_unless($session->isOpen(), 422, 'Sesi sudah selesai, data tidak bisa diubah.');
        $parcel->delete();

        return ['summary' => ScanOut::summary($session)];
    }
}
