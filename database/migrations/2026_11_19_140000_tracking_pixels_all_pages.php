<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved pixels (Pengaturan → Tracking) can be installed on every checkout & landing page of the seller. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_pixels', function (Blueprint $table) {
            $table->boolean('all_pages')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_pixels', function (Blueprint $table) {
            $table->dropColumn('all_pages');
        });
    }
};
