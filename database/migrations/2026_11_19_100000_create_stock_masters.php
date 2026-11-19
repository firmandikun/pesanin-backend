<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok master lintas toko: one stock per SKU shared by every linked Shopee listing (any shop). Orders deduct it
 * once (stock_movements is the idempotency ledger), cancellations restore it, and the value is pushed to all listings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_masters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('name')->nullable();
            $table->string('image')->nullable();
            $table->integer('stock')->default(0);
            // Orders created before this moment were already counted in the starting stock.
            $table->timestamp('tracking_since')->nullable();
            $table->boolean('push_pending')->default(false);
            $table->timestamp('pushed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'sku']);
        });
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_master_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // sale | restore | adjust
            $table->integer('qty');     // signed change to the master stock
            $table->integer('stock_after');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['stock_master_id', 'order_id', 'kind']);
        });
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->foreignId('stock_master_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->string('stock_push_error')->nullable()->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_master_id');
            $table->dropColumn('stock_push_error');
        });
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_masters');
    }
};
