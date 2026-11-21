<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two-track pricing (GTM Playbook v2.0): each store is on the Marketplace or the Social & Web track, may add the
 * other track as add-on, and Enterprise stores have a contract price. Existing stores get the track that fits how
 * they sell today: Marketplace when they have a connected Shopee shop, Social & Web otherwise.
 * Paid periods already running keep the price they paid; the new prices apply from the next invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan_track', 12)->default('marketplace');
            $table->boolean('plan_addon')->default(false);
            $table->unsignedBigInteger('plan_price')->nullable(); // Enterprise contract, per month
        });
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('track', 12)->nullable();
            $table->boolean('addon')->default(false);
        });
        $withShops = DB::table('marketplace_shops')->where('is_demo', false)->distinct()->pluck('user_id');
        DB::table('users')->whereNotIn('id', $withShops->all() ?: [0])->update(['plan_track' => 'social']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['plan_track', 'plan_addon', 'plan_price']));
        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn(['track', 'addon']));
    }
};
