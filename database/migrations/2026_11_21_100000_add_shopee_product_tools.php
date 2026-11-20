<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee product tools: "Naikkan Produk" rotation per shop (Shopee lets 5 products be boosted per 4 hours) and a
 * daily snapshot of each product's views / likes / units sold, so the products page can show the last 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_shops', function (Blueprint $table) {
            // {enabled: bool, items: int[] (rotation order), cursor: int, last_at, last_error, boosted: int[]}
            $table->json('boost')->nullable();
        });
        Schema::create('marketplace_item_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->unsignedInteger('sales')->default(0);
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('comments')->default(0);
            $table->timestamps();
            $table->unique(['marketplace_shop_id', 'item_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_item_stats');
        Schema::table('marketplace_shops', fn (Blueprint $table) => $table->dropColumn('boost'));
    }
};
