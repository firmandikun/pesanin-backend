<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dropship_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 80)->unique();
            $table->string('product_type', 20)->default('physical'); // physical | digital
            $table->string('name');
            $table->string('category', 100)->default('Rumah Tangga');
            $table->string('status', 20)->default('draft'); // active | draft
            $table->boolean('featured')->default(false);
            $table->text('description')->nullable();
            $table->json('images')->nullable();
            $table->unsignedInteger('weight_gram')->default(0);
            $table->unsignedInteger('stock')->default(0);
            $table->string('warehouse_city', 120)->nullable();
            $table->string('digital_format', 180)->nullable();
            $table->string('digital_access_url', 500)->nullable();
            $table->string('digital_license', 160)->nullable();
            $table->unsignedBigInteger('wholesale_price')->default(0);
            $table->unsignedBigInteger('min_price')->default(0);
            $table->unsignedBigInteger('suggested_price')->default(0);
            $table->unsignedBigInteger('compare_price')->default(0);
            $table->json('payment_methods')->nullable(); // ['cod', 'bank_transfer', 'epayment']
            $table->json('variants')->nullable();
            $table->json('videos')->nullable();
            $table->json('slides')->nullable();
            $table->json('lp_templates')->nullable();
            $table->unsignedInteger('sold_30d')->default(0);
            $table->timestamps();
        });

        Schema::create('dropshipper_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_name', 120);
            $table->string('whatsapp', 30);
            $table->string('bank_name', 40)->default('BCA');
            $table->string('bank_account', 60)->nullable();
            $table->string('bank_holder', 100)->nullable();
            $table->json('channels')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dropship_adoptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dropship_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('landing_page_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_name');
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->unsignedBigInteger('compare_price')->default(0);
            $table->string('slug', 140);
            $table->string('selected_lp_id', 80)->nullable();
            $table->json('payment_methods')->nullable();
            $table->json('lp_page')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'dropship_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropship_adoptions');
        Schema::dropIfExists('dropshipper_profiles');
        Schema::dropIfExists('dropship_products');
    }
};
