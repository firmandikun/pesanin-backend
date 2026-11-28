<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CS penanggung jawab: the CS who must confirm / follow up each web order (assigned in turn at checkout),
 * and when each CS last got one (round-robin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('cs_staff_id')->nullable()->after('user_id')->constrained('cs_staff')->nullOnDelete();
            $t->timestamp('cs_contacted_at')->nullable()->after('cs_staff_id');
        });
        Schema::table('cs_staff', function (Blueprint $t) {
            $t->timestamp('last_assigned_at')->nullable()->after('schedule');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('cs_staff_id');
            $t->dropColumn('cs_contacted_at');
        });
        Schema::table('cs_staff', fn (Blueprint $t) => $t->dropColumn('last_assigned_at'));
    }
};
