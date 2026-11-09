<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_insights', function (Blueprint $t) {
            $t->unsignedInteger('reach')->default(0)->after('impressions');
            // Ads Manager's "Hasil": count + what it counts (e.g. actions:omni_landing_page_view).
            $t->unsignedInteger('results')->default(0)->after('clicks');
            $t->string('result_type', 100)->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_insights', fn (Blueprint $t) => $t->dropColumn(['reach', 'results', 'result_type']));
    }
};
