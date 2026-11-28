<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Waktunya beli lagi: when the seller last reminded this customer to reorder (hides them until they buy again). */
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->timestamp('reorder_contacted_at')->nullable()->after('opt_in'));
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('reorder_contacted_at'));
    }
};
