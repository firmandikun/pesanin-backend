<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which Midtrans account took an E-Payment: "platform" (Pesanin's, so Pesanin holds the money for the seller) or
 * "seller" (their own account, paid straight to them). Drives Pengaturan → Saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_via', 10)->nullable()->after('payment_ref');
        });
        // Existing E-Payment orders: the seller's own Midtrans was used when they had one connected, else the platform's.
        $own = DB::table('user_integrations')->where('provider', 'midtrans')->where('status', 'active')->pluck('user_id')->all();
        DB::table('orders')->where('payment_method', 'epayment')->whereNotNull('payment_ref')
            ->update(['payment_via' => DB::raw($own ? 'CASE WHEN user_id IN ('.implode(',', array_map('intval', $own)).") THEN 'seller' ELSE 'platform' END" : "'platform'")]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('payment_via');
        });
    }
};
