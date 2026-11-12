<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Google sign-in, and new stores start on a free trial (see Plans::TRIAL_DAYS); the end date is kept for the admin and reports. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('plan_expires_at');
            $table->string('google_id', 64)->nullable()->unique()->after('email'); // "Masuk dengan Google"
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change(); // Google-only accounts have no password
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['trial_ends_at', 'google_id']));
    }
};
