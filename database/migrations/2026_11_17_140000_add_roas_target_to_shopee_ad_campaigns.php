<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopee_ad_campaigns', function (Blueprint $t) {
            $t->decimal('roas_target', 6, 2)->nullable()->after('budget');
        });
    }

    public function down(): void
    {
        Schema::table('shopee_ad_campaigns', function (Blueprint $t) {
            $t->dropColumn('roas_target');
        });
    }
};
