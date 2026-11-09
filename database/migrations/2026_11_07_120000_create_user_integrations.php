<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each seller connects their own Biteship / Midtrans account (keys stored encrypted).
        Schema::create('user_integrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->text('credentials');
            $t->json('settings')->nullable();
            $t->string('webhook_token', 64)->unique();
            $t->boolean('is_production')->default(false);
            $t->string('status', 20)->default('active');
            $t->string('last_error')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'provider']);
        });

        // Biteship identifies places by its own area id; cached per region once looked up.
        Schema::table('regions', function (Blueprint $t) {
            $t->string('biteship_area_id', 80)->nullable();
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->string('courier_code', 40)->nullable()->after('courier_service');
            $t->string('shipping_provider', 20)->nullable()->after('courier_code');
            $t->string('shipping_ref', 80)->nullable()->after('shipping_provider');
            $t->string('payment_channel', 30)->nullable()->after('payment_method');
            $t->string('payment_ref', 80)->nullable()->after('payment_channel');
            $t->text('payment_url')->nullable()->after('payment_ref');
            $t->string('payment_gateway_status', 30)->nullable()->after('payment_url');
            $t->index('shipping_ref');
        });

        Schema::table('subscription_invoices', function (Blueprint $t) {
            $t->text('payment_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', fn (Blueprint $t) => $t->dropColumn('payment_url'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['courier_code', 'shipping_provider', 'shipping_ref', 'payment_channel', 'payment_ref', 'payment_url', 'payment_gateway_status']));
        Schema::table('regions', fn (Blueprint $t) => $t->dropColumn('biteship_area_id'));
        Schema::dropIfExists('user_integrations');
    }
};
