<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** How far back a shop's orders are guaranteed synced. A report asking for an earlier date triggers a backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_shops', function (Blueprint $table) {
            $table->date('orders_backfilled_from')->nullable()->after('orders_synced_at');
        });
        // Existing shops: assume the first sync covered the last 15 days (matches ShopeeSync::orders first run).
        DB::table('marketplace_shops')->whereNotNull('orders_synced_at')
            ->update(['orders_backfilled_from' => now()->subDays(15)->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('marketplace_shops', fn (Blueprint $t) => $t->dropColumn('orders_backfilled_from'));
    }
};
