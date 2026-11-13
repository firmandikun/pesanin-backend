<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * When a report asks for a date earlier than a Shopee shop has synced, pull that older range from Shopee in the
 * background so the numbers catch up. The shop's orders_backfilled_from marker stops the same range re-running.
 */
class ShopeeBackfill
{
    /** @return bool true if any shop still needs (or just started) a backfill for this range → report shows "updating". */
    public static function ensure(User $user, CarbonInterface $from): bool
    {
        if (! ShopeeClient::configured()) {
            return false;
        }
        $from = Carbon::parse($from)->startOfDay();
        $floor = now()->subYears(2)->startOfDay();
        if ($from->lt($floor)) {
            $from = $floor;
        }
        $pending = false;
        $shops = $user->marketplaceShops()->where('platform', 'shopee')->where('status', 'active')->where('is_demo', false)->get();
        foreach ($shops as $shop) {
            $covered = $shop->orders_backfilled_from?->copy()->startOfDay();
            if (! $covered || $from->gte($covered)) {
                continue; // range already covered, or orders never synced yet (initial sync will cover it)
            }
            $pending = true;
            // One backfill process per shop at a time.
            if (Cache::add("shopee:backfill:{$shop->id}", $from->toDateString(), now()->addMinutes(20))) {
                self::dispatch($shop->id, $from);
            }
        }

        return $pending;
    }

    /** Backfill one shop (the Shopee transactions page is scoped to a single shop). */
    public static function ensureShop(MarketplaceShop $shop, CarbonInterface $from): bool
    {
        if ($shop->is_demo || $shop->platform !== 'shopee') {
            return false;
        }
        $from = Carbon::parse($from)->startOfDay()->max(now()->subYears(2)->startOfDay());
        $covered = $shop->orders_backfilled_from?->copy()->startOfDay();
        if (! $covered || $from->gte($covered)) {
            return false;
        }
        if (Cache::add("shopee:backfill:{$shop->id}", $from->toDateString(), now()->addMinutes(20))) {
            self::dispatch($shop->id, $from);
        }

        return true;
    }

    private static function dispatch(int $shopId, Carbon $from): void
    {
        $php = (new PhpExecutableFinder)->find(false);
        if ($php && function_exists('exec') && ! app()->runningUnitTests()) {
            @exec(sprintf('nohup %s %s marketplace:backfill %d %s >> %s 2>&1 &',
                escapeshellarg($php), escapeshellarg(base_path('artisan')), $shopId, escapeshellarg($from->toDateString()),
                escapeshellarg(storage_path('logs/backfill.log'))));

            return;
        }
        dispatch(function () use ($shopId, $from) {
            if ($s = MarketplaceShop::find($shopId)) {
                try {
                    (new ShopeeSync)->backfill($s, $from);
                } finally {
                    Cache::forget("shopee:backfill:{$s->id}");
                }
            }
        })->afterResponse();
    }
}
