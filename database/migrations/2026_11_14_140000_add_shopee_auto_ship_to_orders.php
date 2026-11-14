<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // True for Shopee-fulfilled / Advance Fulfilment orders the seller must NOT arrange manually.
            $table->boolean('shopee_auto_ship')->default(false)->after('external_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('shopee_auto_ship'));
    }
};
