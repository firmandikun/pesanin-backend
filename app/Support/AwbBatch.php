<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ShopeeBooking;
use App\Models\User;
use App\Support\Shopee\ShopeeAwb;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Server-side Shopee AWB printing, shared by the scheduled Telegram send (AwbSchedule) and background "cetak resi"
 * jobs (AwbPrintJob):
 *   1. pick printable orders / Pengiriman Kilat reservations,
 *   2. re-read their status from Shopee and drop parcels already handed over,
 *   3. download Shopee's labels per shop + courier in chunks of 50 (all documents requested first, so Shopee
 *      builds them in parallel, then downloaded one by one),
 *   4. format them with the same code as the browser (backend/scripts/awb/awb.mjs, built from the frontend).
 */
class AwbBatch
{
    public const PRINTABLE = ['PROCESSED', 'RETRY_SHIP', 'IN_CANCEL'];

    public const CHUNK = 50;

    /**
     * @param  string  $scope  new (not printed yet) | all (every printable label) | selected (only these ids)
     * @return array{0: Collection, 1: Collection}
     */
    public static function collect(User $user, string $scope, array $orderIds = [], array $bookingIds = [], int $limit = 5000): array
    {
        $notDemo = fn ($q) => $q->where('is_demo', false);
        $selected = $scope === 'selected';
        $orders = $selected && ! $orderIds ? collect() : $user->orders()->active()->where('channel', 'shopee')->whereIn('external_status', self::PRINTABLE)
            ->whereNotNull('tracking_number')->where('tracking_number', '!=', '')
            ->when($scope === 'new', fn ($q) => $q->whereNull('awb_printed_at'))->when($selected, fn ($q) => $q->whereIn('id', $orderIds))
            ->whereHas('marketplaceShop', $notDemo)->with('marketplaceShop')->orderBy('id')->limit($limit)->get();
        $bookings = $selected && ! $bookingIds ? collect() : ShopeeBooking::where('user_id', $user->id)->where('booking_status', 'PROCESSED')
            ->whereNotNull('tracking_number')->where('tracking_number', '!=', '')
            ->when($scope === 'new', fn ($q) => $q->whereNull('awb_printed_at'))->when($selected, fn ($q) => $q->whereIn('id', $bookingIds))
            ->whereHas('marketplaceShop', $notDemo)->with('marketplaceShop')->orderBy('id')->limit($limit)->get();

        return [$orders, $bookings];
    }

    /**
     * Re-read order status from Shopee; parcels already on their way can't be printed again (they move to "Dikirim").
     * @return array{0: Collection, 1: list<string>} printable orders, skipped order numbers
     */
    public static function refresh(Collection $orders): array
    {
        $sync = new ShopeeSync;
        $api = new ShopeeClient;
        foreach ($orders->groupBy('marketplace_shop_id') as $group) {
            foreach ($group->chunk(self::CHUNK) as $chunk) {
                try {
                    foreach ($api->orderDetail($chunk->first()->marketplaceShop, $chunk->pluck('external_order_sn')->all()) as $d) {
                        $sync->upsertOrder($chunk->first()->marketplaceShop, $d);
                    }
                } catch (ShopeeException) {
                    // Shopee unreachable: decide on the stored status.
                }
            }
        }
        $fresh = Order::whereIn('id', $orders->pluck('id'))->with('marketplaceShop')->orderBy('id')->get();
        $skipped = $fresh->reject(fn ($o) => in_array($o->external_status, self::PRINTABLE, true))->pluck('external_order_sn')->values()->all();

        return [$fresh->filter(fn ($o) => in_array($o->external_status, self::PRINTABLE, true))->values(), $skipped];
    }

    /**
     * Download every label (chunks of 50 per shop + courier). $progress(int $labelsDone) is called after each chunk.
     * @return array{0: list<array>, 1: list<int>, 2: list<int>, 3: list<string>} parts, order ids, booking ids, failures
     */
    public static function download(string $dir, Collection $orders, Collection $bookings, ?callable $progress = null): array
    {
        ShopeeSync::withListingSkus($orders);
        $awb = new ShopeeAwb;
        $chunks = [];
        foreach ($orders->groupBy(fn ($o) => $o->marketplace_shop_id.'|'.$o->courier) as $group) {
            foreach ($group->chunk(self::CHUNK) as $c) {
                $chunks[] = ['kind' => 'order', 'list' => $c->values()];
            }
        }
        foreach ($bookings->groupBy(fn ($b) => $b->marketplace_shop_id.'|'.$b->shipping_carrier) as $group) {
            foreach ($group->chunk(self::CHUNK) as $c) {
                $chunks[] = ['kind' => 'booking', 'list' => $c->values()];
            }
        }
        $failed = [];
        $label = fn ($c) => $c['list']->pluck($c['kind'] === 'order' ? 'external_order_sn' : 'booking_sn')->implode(', ');
        // 1. Ask Shopee to build every document.
        foreach ($chunks as $i => $c) {
            try {
                $c['kind'] === 'order' ? $awb->startOrders($c['list']->first()->marketplaceShop, $c['list']) : $awb->startBookings($c['list']->first()->marketplaceShop, $c['list']);
            } catch (ShopeeException $e) {
                $failed[] = $label($c).' ('.$e->getMessage().')';
                unset($chunks[$i]);
            }
        }
        // 2. Download them (most are ready by now).
        $parts = [];
        $doneOrders = [];
        $doneBookings = [];
        $done = 0;
        foreach ($chunks as $c) {
            try {
                $shop = $c['list']->first()->marketplaceShop;
                $path = "{$dir}/p".count($parts).'.pdf';
                file_put_contents($path, $c['kind'] === 'order' ? $awb->finishOrders($shop, $c['list']) : $awb->finishBookings($shop, $c['list']));
                $parts[] = ['pdf' => $path, 'orders' => $c['list']->map(fn ($x) => [
                    'sns' => array_values(array_filter($c['kind'] === 'order' ? [$x->external_order_sn, $x->tracking_number] : [$x->booking_sn, $x->order_sn, $x->tracking_number])),
                    'rows' => self::rows($x->items),
                ])->values()->all()];
                $c['kind'] === 'order' ? array_push($doneOrders, ...$c['list']->pluck('id')->all()) : array_push($doneBookings, ...$c['list']->pluck('id')->all());
            } catch (ShopeeException $e) {
                $failed[] = $label($c).' ('.$e->getMessage().')';
            }
            $done += $c['list']->count();
            if ($progress) {
                $progress($done);
            }
        }

        return [$parts, $doneOrders, $doneBookings, $failed];
    }

    /** "# | SKU | Variasi | Qty" rows, the same shape the browser uses (frontend/src/lib/awb.ts → toLabel). */
    public static function rows(?array $items): array
    {
        return collect($items ?? [])->map(fn ($i) => [
            'parent' => (string) (($i['item_sku'] ?? null) ?: (($i['sku'] ?? null) ?: '-')),
            'variant' => (string) (($i['model_sku'] ?? null) ?: '-'),
            'name' => trim((string) ($i['name'] ?? '')) ?: null,
            'varName' => trim((string) ($i['variant'] ?? '')) ?: null,
            'qty' => (int) ($i['qty'] ?? 1),
        ])->values()->all();
    }

    /** Format and merge the parts with the Node formatter. Returns the page count, or null when it could not run. */
    public static function render(array $parts, string $out, array $printSettings = [], int $timeout = 600): ?int
    {
        $node = config('services.node.binary') ?: (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/usr/bin']);
        $script = base_path('scripts/awb/awb.mjs');
        if (! $node || ! is_file($script) || ! is_dir(base_path('scripts/awb/node_modules'))) {
            return null;
        }
        $job = dirname($out).'/job.json';
        file_put_contents($job, json_encode(['output' => $out, 'parts' => $parts, 'print_settings' => $printSettings]));
        $p = new Process([$node, '--max-old-space-size=2048', $script, $job], base_path('scripts/awb'));
        $p->setTimeout($timeout);
        $p->run();
        if (! $p->isSuccessful() || ! is_file($out)) {
            Log::warning('AWB render failed: '.mb_substr($p->getErrorOutput() ?: $p->getOutput(), 0, 500));

            return null;
        }

        return (int) (json_decode(trim(collect(explode("\n", trim($p->getOutput())))->last()), true)['pages'] ?? 0);
    }
}
