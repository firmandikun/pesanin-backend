<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dropship fulfilment: an order of an adopted catalog product remembers its catalog source and the wholesale cost
 * (modal) the seller owes Pesanin; the warehouse ships it. Catalog stock is counted down from real orders since the
 * admin last set it. Orders over the monthly plan quota are flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('dropship_product_id')->nullable()->constrained('dropship_products')->nullOnDelete();
            $table->unsignedBigInteger('dropship_cost')->default(0);
            $table->timestamp('dropship_paid_at')->nullable(); // modal received by Pesanin
            $table->boolean('over_quota')->default(false);
        });
        Schema::table('dropship_products', function (Blueprint $table) {
            $table->timestamp('stock_set_at')->nullable()->after('stock');
        });
        DB::table('dropship_products')->update(['stock_set_at' => now()]);
        // Existing orders of adopted products.
        foreach (DB::table('products')->whereNotNull('dropship_product_id')->get(['id', 'dropship_product_id']) as $p) {
            $wholesale = (int) DB::table('dropship_products')->where('id', $p->dropship_product_id)->value('wholesale_price');
            DB::table('orders')->where('product_id', $p->id)->update(['dropship_product_id' => $p->dropship_product_id, 'dropship_cost' => DB::raw("quantity * {$wholesale}")]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dropship_product_id');
            $table->dropColumn(['dropship_cost', 'dropship_paid_at', 'over_quota']);
        });
        Schema::table('dropship_products', function (Blueprint $table) {
            $table->dropColumn('stock_set_at');
        });
    }
};
