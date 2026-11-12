<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee income per order: keep Shopee's fee lines and whether the amount is final (escrow released),
 * and the PPN Shopee charges on top of ad spend (the Ads API reports spend before PPN).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('escrow_final')->default(false)->after('escrow_amount');
            $table->json('fee_breakdown')->nullable()->after('escrow_final');
        });
        Schema::table('marketplace_shops', function (Blueprint $table) {
            $table->decimal('ads_ppn', 5, 2)->default(11)->after('ads_balance_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['escrow_final', 'fee_breakdown']));
        Schema::table('marketplace_shops', fn (Blueprint $t) => $t->dropColumn('ads_ppn'));
    }
};
