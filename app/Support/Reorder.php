<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Waktunya beli lagi: web/social buyers (checkout, manual, WhatsApp; not Shopee) whose last paid order was about
 * one buying cycle ago and who have not ordered since. The seller reminds them with one tap on WhatsApp, or sends
 * a broadcast to the whole segment. A reminded customer is hidden until they buy again.
 */
class Reorder
{
    public const KEY = 'reorder';

    /** Buyers stay on the list this many days after the cycle is reached, then drop off (too late to remind). */
    public const WINDOW = 30;

    public const DEFAULT_MESSAGE = "Halo kak {nama} 👋\nTerima kasih sudah belanja {produk} di {toko} bulan lalu. Biasanya sudah waktunya stok lagi nih 😊\nMau dibantu pesan lagi? Bisa langsung lewat sini: {link}";

    public static function settings(User $user): array
    {
        $s = (array) ($user->settings[self::KEY] ?? []);

        return [
            'days' => max(7, min(180, (int) ($s['days'] ?? 30))),
            'message' => trim((string) ($s['message'] ?? '')) ?: self::DEFAULT_MESSAGE,
        ];
    }

    /**
     * Customers due for a reminder, most overdue first.
     * @return array<int, array{phone: string, name: string, last_order_at: string, days_since: int, orders: int, spent: int, products: array<int, string>, product_slug: ?string}>
     */
    public static function due(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $days = self::settings($user)['days'];
        $orders = Order::where('user_id', $user->id)->active()->where('channel', '!=', 'shopee')
            ->whereNotIn('status', ['cancelled', 'pending'])->whereNotNull('phone')
            ->where('created_at', '>=', $now->copy()->subDays($days + self::WINDOW))
            ->with('product:id,name,slug,type')->orderBy('created_at')->get(['id', 'phone', 'customer_name', 'product_id', 'items', 'total', 'created_at']);

        $byPhone = [];
        foreach ($orders as $o) {
            if (! ($p = Customer::normalizePhone($o->phone)) || strlen($p) < 9) {
                continue;
            }
            $byPhone[$p][] = $o;
        }
        $contacted = Customer::where('user_id', $user->id)->whereNotNull('reorder_contacted_at')->get(['phone', 'reorder_contacted_at'])
            ->mapWithKeys(fn ($c) => [(string) Customer::normalizePhone($c->phone) => $c->reorder_contacted_at])->all();

        $out = [];
        foreach ($byPhone as $phone => $list) {
            $last = end($list);
            $since = (int) $last->created_at->diffInDays($now);
            if ($since < $days) {
                continue; // bought recently (or not yet a full cycle)
            }
            if (isset($contacted[$phone]) && $contacted[$phone]->gte($last->created_at)) {
                continue; // already reminded after their last purchase
            }
            $physical = collect($list)->filter(fn ($o) => ($o->product?->type ?? 'physical') !== 'digital');
            if ($physical->isEmpty()) {
                continue; // digital products are not "restocked"
            }
            $lastPhysical = $physical->last();
            $out[] = [
                // (string): numeric array keys come back as int.
                'phone' => (string) $phone, 'name' => trim((string) $last->customer_name) ?: 'Kak',
                'last_order_at' => $last->created_at->toIso8601String(), 'days_since' => $since,
                'orders' => count($list), 'spent' => (int) collect($list)->sum('total'),
                'products' => $physical->map(fn ($o) => $o->product?->name ?? ($o->items[0]['name'] ?? null))->filter()->unique()->values()->all(),
                'product_slug' => $lastPhysical->product?->slug,
            ];
        }
        usort($out, fn ($a, $b) => $b['days_since'] <=> $a['days_since']);

        return $out;
    }

    /** The WhatsApp text for one customer: {nama}, {produk}, {toko}, {link}. */
    public static function message(User $user, array $row): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $link = $row['product_slug'] ? "{$base}/c/{$row['product_slug']}" : $base;

        return strtr(self::settings($user)['message'], [
            '{nama}' => $row['name'], '{produk}' => $row['products'][0] ?? 'produk kami', '{toko}' => $user->store_name ?: $user->name, '{link}' => $link,
        ]);
    }

    /** Remember the reminder (creates the customer when they only exist as orders). */
    public static function markContacted(User $user, string $phone, ?string $name = null): Customer
    {
        $p = Customer::normalizePhone($phone);
        $c = Customer::where('user_id', $user->id)->get(['id', 'phone'])->first(fn ($x) => Customer::normalizePhone($x->phone) === $p);
        $c = $c ? Customer::find($c->id) : new Customer(['name' => $name ?: 'Pelanggan', 'phone' => $p, 'source' => 'order']);
        $c->user_id = $user->id;
        $c->reorder_contacted_at = now();
        $c->save();

        return $c;
    }

    /** Daily: tell the seller about customers who newly became due (each once). */
    public static function notify(User $user): int
    {
        if (! Alert::enabled($user, 'reorder')) {
            return 0;
        }
        $due = self::due($user);
        $key = "reorder_notified:{$user->id}";
        $seen = array_map('strval', Cache::get($key, []));
        $new = array_values(array_filter($due, fn ($r) => ! in_array($r['phone'], $seen, true)));
        Cache::put($key, array_values(array_unique(array_merge($seen, array_column($due, 'phone')))), now()->addDays(90));
        if (! $new) {
            return 0;
        }
        $lines = collect($new)->take(6)->map(fn ($r) => "• {$r['name']}: ".($r['products'][0] ?? 'produk').", terakhir {$r['days_since']} hari lalu")->implode("\n");
        Alert::send($user, 'reorder', count($new).' pelanggan waktunya beli lagi', $lines.(count($new) > 6 ? "\n+".(count($new) - 6).' lainnya' : '')."\n\nIngatkan lewat WhatsApp dengan sekali klik.", '/customers/reorder', '🔁');

        return count($new);
    }
}
