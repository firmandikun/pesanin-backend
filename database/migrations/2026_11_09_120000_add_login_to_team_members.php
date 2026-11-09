<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Team members can sign in with their own email + password (acting inside the owner's store). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_members', function (Blueprint $t) {
            $t->string('password')->nullable()->after('role');
            $t->timestamp('last_login_at')->nullable()->after('password');
            $t->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $t) {
            $t->dropIndex(['email']);
            $t->dropColumn(['password', 'last_login_at']);
        });
    }
};
