<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            // HPP per Shopee listing/variation, used by the profit report.
            $table->unsignedBigInteger('cost_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', fn (Blueprint $t) => $t->dropColumn('cost_price'));
    }
};
