<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Packing check: when an order was packed, by whom, the photo taken as proof, and what was scanned. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->timestamp('packed_at')->nullable()->index();
            $t->string('packed_by', 100)->nullable();
            $t->string('packing_photo', 255)->nullable();
            $t->json('packing_check')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['packed_at', 'packed_by', 'packing_photo', 'packing_check']);
        });
    }
};
