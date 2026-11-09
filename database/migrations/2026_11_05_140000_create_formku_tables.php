<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('name');
            $table->string('store_slug')->nullable()->unique()->after('store_name');
            $table->string('whatsapp', 30)->nullable()->after('email');
            $table->json('bank_accounts')->nullable()->after('whatsapp');
            $table->decimal('cod_fee_percent', 4, 2)->default(3)->after('bank_accounts');
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('province');
            $table->string('city');
            $table->string('district');
            $table->string('island');
            $table->index(['district', 'city']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('site_title')->nullable();
            $table->enum('type', ['physical', 'digital'])->default('physical');
            $table->string('category')->nullable();
            $table->enum('status', ['active', 'draft'])->default('active');
            $table->boolean('is_preorder')->default(false);
            $table->unsignedSmallInteger('preorder_days')->nullable();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedBigInteger('sale_price')->nullable();
            $table->unsignedBigInteger('cost_price')->nullable();
            $table->json('wholesale_prices')->nullable();
            $table->string('sku')->nullable();
            $table->boolean('track_stock')->default(false);
            $table->integer('stock')->default(0);
            $table->boolean('allow_oversell')->default(false);
            $table->unsignedInteger('min_purchase')->default(1);
            $table->unsignedInteger('weight')->default(1000);
            $table->json('images')->nullable();
            $table->boolean('autoslide')->default(false);
            $table->text('description')->nullable();
            $table->json('features')->nullable();
            $table->json('variants')->nullable();
            $table->json('bump')->nullable();
            $table->json('payment_methods')->nullable();
            $table->boolean('whatsapp_validator')->default(false);
            $table->boolean('captcha')->default(false);
            $table->string('button_text')->default('Beli Sekarang');
            $table->json('form_settings')->nullable();
            $table->json('success_settings')->nullable();
            $table->json('pixels')->nullable();
            $table->unsignedBigInteger('views')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('customer_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('variant')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->boolean('bump_selected')->default(false);
            $table->unsignedBigInteger('bump_price')->default(0);
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('cod_fee')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedInteger('weight')->default(0);
            $table->enum('payment_method', ['bank_transfer', 'cod', 'epayment'])->nullable();
            $table->enum('payment_status', ['unpaid', 'paid'])->default('unpaid');
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled', 'refunded'])->default('pending');
            $table->string('courier')->nullable();
            $table->string('courier_service')->nullable();
            $table->string('shipping_etd')->nullable();
            $table->enum('shipping_status', ['not_shipped', 'picked_up', 'in_transit', 'delivered', 'returned'])->default('not_shipped');
            $table->string('tracking_number')->nullable();
            $table->foreignId('pickup_address_id')->nullable();
            $table->enum('source', ['checkout', 'manual'])->default('checkout');
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_abandoned')->default(false);
            $table->string('checkout_token', 64)->nullable()->index();
            $table->unsignedSmallInteger('followup_count')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('name');
            $table->string('phone', 30);
            $table->text('address');
            $table->foreignId('region_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('followup_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->string('name');
            $table->text('message');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('banned_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['ip', 'phone']);
            $table->string('value');
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action');
            $table->string('description');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('banned_devices');
        Schema::dropIfExists('followup_templates');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');
        Schema::dropIfExists('regions');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['store_name', 'store_slug', 'whatsapp', 'bank_accounts', 'cod_fee_percent']));
    }
};
