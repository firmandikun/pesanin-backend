<?php

namespace App\Support\Meta;

use App\Http\Controllers\Api\MetaChatController;
use App\Models\UserIntegration;
use App\Support\Notifier;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** New Messenger / Instagram message from a buyer → notification, like Shopee chat. Runs every 30s. */
class MetaChatWatch
{
    public static function check(UserIntegration $i): int
    {
        if (Cache::has("meta:chat:off:{$i->id}")) {
            return 0;
        }
        try {
            $inbox = MetaChatController::inbox($i->user, fresh: true); // also warms the inbox cache for the Chat page
        } catch (Throwable) {
            Cache::put("meta:chat:off:{$i->id}", true, now()->addMinutes(30));

            return 0;
        }
        $primed = Cache::has("meta:chat:primed:{$i->id}");
        $sent = 0;
        foreach ($inbox['conversations'] as $c) {
            $key = "meta:chat:seen:{$i->id}:{$c['id']}";
            if (! $c['last_message_id'] || Cache::get($key) === $c['last_message_id']) {
                continue;
            }
            Cache::forever($key, $c['last_message_id']);
            if ($primed && ! $c['last_from_me'] && $c['unread'] > 0) {
                Notifier::send($i->user_id, 'chat', $c['channel'] === 'instagram' ? 'Pesan Instagram baru' : 'Pesan Facebook baru',
                    mb_strimwidth("{$c['name']}: {$c['last_text']}", 0, 120, '…')." · {$c['source_name']}",
                    "/chat?channel={$c['channel']}&source={$c['source_id']}&c=".urlencode($c['id']));
                $sent++;
            }
        }
        Cache::forever("meta:chat:primed:{$i->id}", true);

        return $sent;
    }
}
