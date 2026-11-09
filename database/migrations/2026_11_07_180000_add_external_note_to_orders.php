<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Latest courier/logistics message from the marketplace, e.g. why a pickup failed.
        Schema::table('orders', fn (Blueprint $t) => $t->string('external_note')->nullable()->after('external_status'));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('external_note'));
    }
};
