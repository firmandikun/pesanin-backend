<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('settings')->nullable()->after('cod_fee_percent'));
        Schema::table('orders', function (Blueprint $t) {
            $t->string('coupon_code')->nullable()->after('discount');
            $t->foreignId('affiliate_id')->nullable()->after('coupon_code');
        });

        Schema::create('custom_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('domain');
            $t->enum('status', ['pending', 'connected'])->default('pending');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });
        Schema::create('tracking_pixels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('platform', ['facebook', 'tiktok', 'google_ads', 'gtm', 'snack']);
            $t->string('pixel_id');
            $t->string('name')->nullable();
            $t->boolean('server_side')->default(false);
            $t->timestamps();
        });
        Schema::create('notification_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('type', ['email', 'telegram']);
            $t->string('name');
            $t->string('value');
            $t->boolean('is_active')->default(true);
            $t->json('events')->nullable();
            $t->timestamps();
        });
        Schema::create('cs_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone', 30);
            $t->boolean('is_active')->default(true);
            $t->json('schedule')->nullable();
            $t->timestamps();
        });
        Schema::create('staff_time_offs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cs_staff_id')->constrained('cs_staff')->cascadeOnDelete();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('wa_rotators', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title');
            $t->string('slug')->unique();
            $t->json('staff_ids')->nullable();
            $t->json('settings')->nullable();
            $t->unsignedInteger('cursor')->default(0);
            $t->timestamps();
        });
        Schema::create('wa_rotator_hits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('wa_rotator_id')->constrained()->cascadeOnDelete();
            $t->foreignId('cs_staff_id')->nullable()->constrained('cs_staff')->nullOnDelete();
            $t->string('visitor_id', 40)->index();
            $t->string('chat_id', 20);
            $t->boolean('is_returning')->default(false);
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('team_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('role')->default('cs');
            $t->boolean('accept_orders')->default(true);
            $t->boolean('landing_access')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('affiliates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('code', 40);
            $t->decimal('commission_percent', 5, 2)->default(10);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['user_id', 'code']);
        });
        Schema::create('coupons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('kind', ['product', 'order', 'shipping']);
            $t->enum('method', ['code', 'auto'])->default('code');
            $t->string('code')->nullable();
            $t->enum('product_type', ['physical', 'digital'])->default('physical');
            $t->json('product_ids')->nullable();
            $t->enum('value_type', ['percent', 'amount'])->default('amount');
            $t->unsignedBigInteger('value')->default(0);
            $t->enum('payment_type', ['all', 'cod', 'non_cod'])->default('all');
            $t->enum('min_type', ['none', 'amount', 'qty'])->default('none');
            $t->unsignedBigInteger('min_value')->default(0);
            $t->boolean('exclusive')->default(false);
            $t->unsignedInteger('max_uses')->nullable();
            $t->boolean('once_per_customer')->default(false);
            $t->unsignedInteger('used_count')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('payment_confirmations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_code');
            $t->string('account_name');
            $t->string('bank')->nullable();
            $t->unsignedBigInteger('amount');
            $t->date('transfer_date');
            $t->string('proof')->nullable();
            $t->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $t->foreignId('processed_by')->nullable();
            $t->timestamps();
        });
        Schema::create('landing_page_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $t->string('visitor_id', 40)->index();
            $t->string('session_id', 40)->index();
            $t->string('referrer_domain')->nullable();
            $t->string('referrer_url', 500)->nullable();
            $t->boolean('is_new')->default(true);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('max_scroll')->default(0);
            $t->unsignedInteger('duration')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['landing_page_visits', 'payment_confirmations', 'coupons', 'affiliates', 'team_members', 'wa_rotator_hits', 'wa_rotators',
            'staff_time_offs', 'cs_staff', 'notification_accounts', 'tracking_pixels', 'custom_domains'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['coupon_code', 'affiliate_id']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('settings'));
    }
};
