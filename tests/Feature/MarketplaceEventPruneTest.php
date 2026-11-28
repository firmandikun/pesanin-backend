<?php

namespace Tests\Feature;

use App\Models\MarketplaceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The raw Shopee push log is pruned after MARKETPLACE_EVENTS_KEEP_DAYS; pending events are kept. */
class MarketplaceEventPruneTest extends TestCase
{
    use RefreshDatabase;

    private function event(int $daysAgo, ?string $processed, ?string $error = null): MarketplaceEvent
    {
        $e = MarketplaceEvent::create(['platform' => 'shopee', 'shop_id' => 1, 'code' => 3, 'payload' => ['code' => 3], 'processed_at' => $processed, 'error' => $error]);
        $e->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $e;
    }

    public function test_prunes_old_applied_and_failed_events_only(): void
    {
        $oldApplied = $this->event(31, now()->subDays(31)->toDateTimeString());
        $oldFailed = $this->event(45, null, 'Invalid access_token');
        $oldPending = $this->event(40, null);
        $recent = $this->event(29, now()->subDays(29)->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [MarketplaceEvent::class]])->assertSuccessful();

        $this->assertModelMissing($oldApplied);
        $this->assertModelMissing($oldFailed);
        $this->assertModelExists($oldPending);
        $this->assertModelExists($recent);
    }

    public function test_keep_days_is_configurable_and_scheduled(): void
    {
        config(['services.marketplace_events_keep_days' => 90]);
        $kept = $this->event(60, now()->toDateTimeString());
        $gone = $this->event(91, now()->toDateTimeString());

        $this->artisan('model:prune', ['--model' => [MarketplaceEvent::class]])->assertSuccessful();

        $this->assertModelExists($kept);
        $this->assertModelMissing($gone);
        $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
    }
}
