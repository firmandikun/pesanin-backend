<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('regions', function (Blueprint $t) {
            $t->string('postal_code', 10)->nullable();
            $t->index('biteship_area_id');
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $t) {
            $t->dropIndex(['biteship_area_id']);
            $t->dropColumn('postal_code');
        });
    }
};
