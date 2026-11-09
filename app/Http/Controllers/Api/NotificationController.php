<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Support\Notifier;
use Illuminate\Http\Request;

/** Bell feed (polled by the app) and Web Push subscriptions. */
class NotificationController extends Controller
{
    /** Latest notifications; `after` returns only newer ones so polling stays cheap. */
    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $after = $request->integer('after');
        $q = AppNotification::where('user_id', $uid)->latest('id');

        return [
            'items' => ($after ? $q->where('id', '>', $after) : $q)->limit(20)->get(['id', 'type', 'title', 'body', 'url', 'order_id', 'read_at', 'created_at']),
            'unread' => AppNotification::where('user_id', $uid)->whereNull('read_at')->count(),
            'push' => ['key' => config('services.webpush.public_key'), 'enabled' => Notifier::vapidReady()],
        ];
    }

    public function read(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => 'required|url|max:500', 'keys.p256dh' => 'required|string', 'keys.auth' => 'required|string']);
        PushSubscription::updateOrCreate(['endpoint' => $data['endpoint']], [
            'user_id' => $request->user()->id, 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
        ]);

        return response()->noContent();
    }

    public function unsubscribe(Request $request)
    {
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint', $request->input('endpoint'))->delete();

        return response()->noContent();
    }

    /** Sends a test notification to the bell and every subscribed browser. */
    public function test(Request $request)
    {
        Notifier::send($request->user()->id, 'test', 'Notifikasi aktif', 'Kamu akan dapat kabar setiap ada pesanan atau chat baru.', '/orders');

        return response()->noContent();
    }
}
