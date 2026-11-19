<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Meta Conversions API per saved Facebook pixel: access token (encrypted), optional test code, last send status. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_pixels', function (Blueprint $table) {
            $table->text('capi_token')->nullable()->after('server_side');
            $table->string('capi_test_code', 40)->nullable()->after('capi_token');
            $table->timestamp('capi_last_at')->nullable()->after('capi_test_code');
            $table->string('capi_last_error', 300)->nullable()->after('capi_last_at');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_pixels', function (Blueprint $table) {
            $table->dropColumn(['capi_token', 'capi_test_code', 'capi_last_at', 'capi_last_error']);
        });
    }
};
