<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_listings', function (Blueprint $table) {
            $table->string('item_sku')->nullable()->after('model_name');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_listings', fn (Blueprint $t) => $t->dropColumn('item_sku'));
    }
};
