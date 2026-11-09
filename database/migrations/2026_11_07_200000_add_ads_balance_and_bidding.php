<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saldo iklan from v2.ads.get_total_balance, refreshed with every ads sync.
        Schema::table('marketplace_shops', function (Blueprint $t) {
            $t->unsignedBigInteger('ads_balance')->nullable();
            $t->timestamp('ads_balance_at')->nullable();
        });
        Schema::table('shopee_ad_campaigns', fn (Blueprint $t) => $t->string('bidding_method', 20)->nullable()->after('ad_type'));
    }

    public function down(): void
    {
        Schema::table('marketplace_shops', fn (Blueprint $t) => $t->dropColumn(['ads_balance', 'ads_balance_at']));
        Schema::table('shopee_ad_campaigns', fn (Blueprint $t) => $t->dropColumn('bidding_method'));
    }
};
