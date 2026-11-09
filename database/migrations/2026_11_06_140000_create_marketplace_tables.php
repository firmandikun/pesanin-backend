<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20)->default('shopee');
            $table->unsignedBigInteger('shop_id');
            $table->string('shop_name')->nullable();
            $table->string('region', 5)->default('ID');
            $table->string('logo')->nullable();
            $table->string('status', 15)->default('active'); // active | expired | disconnected
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('products_synced_at')->nullable();
            $table->timestamp('orders_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'platform', 'shop_id']);
        });

        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('model_id')->default(0);
            $table->string('name');
            $table->string('model_name')->nullable();
            $table->string('sku')->nullable()->index();
            $table->unsignedBigInteger('price')->default(0);
            $table->integer('stock')->default(0);
            $table->string('status', 20)->default('NORMAL');
            $table->string('image')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_shop_id', 'item_id', 'model_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel', 20)->default('website')->after('source')->index();
            $table->foreignId('marketplace_shop_id')->nullable()->after('channel')->constrained()->nullOnDelete();
            $table->string('external_order_sn', 40)->nullable()->after('marketplace_shop_id');
            $table->string('external_status', 30)->nullable()->after('external_order_sn');
            $table->json('items')->nullable()->after('external_status');
            $table->unsignedBigInteger('marketplace_fee')->default(0)->after('discount');
            $table->unsignedBigInteger('seller_shipping_fee')->default(0)->after('marketplace_fee');
            $table->bigInteger('escrow_amount')->nullable()->after('seller_shipping_fee');
            $table->unique(['marketplace_shop_id', 'external_order_sn']);
        });
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE orders MODIFY source ENUM('checkout','manual','marketplace') NOT NULL DEFAULT 'checkout'");
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('bank_transfer','cod','epayment','marketplace') NULL");
        DB::table('orders')->where('source', 'manual')->update(['channel' => 'manual']);

        Schema::create('ad_spends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20); // meta | tiktok | google | shopee_ads | other
            $table->foreignId('marketplace_shop_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->date('spent_on');
            $table->unsignedBigInteger('amount');
            $table->string('campaign')->nullable();
            $table->string('note')->nullable();
            $table->boolean('synced')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'spent_on']);
        });

        Schema::create('marketplace_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->unsignedInteger('code');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_events');
        Schema::dropIfExists('ad_spends');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['marketplace_shop_id', 'external_order_sn']);
            $table->dropConstrainedForeignId('marketplace_shop_id');
            $table->dropColumn(['channel', 'external_order_sn', 'external_status', 'items', 'marketplace_fee', 'seller_shipping_fee', 'escrow_amount']);
        });
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE orders MODIFY source ENUM('checkout','manual') NOT NULL DEFAULT 'checkout'");
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE orders MODIFY payment_method ENUM('bank_transfer','cod','epayment') NULL");
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_shops');
    }
};
