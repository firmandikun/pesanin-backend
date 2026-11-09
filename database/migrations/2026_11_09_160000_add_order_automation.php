<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic courier booking (resi) once paid, and automatic delivery of digital products. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->unsignedTinyInteger('auto_ship_tries')->default(0);
            $t->string('auto_ship_error', 300)->nullable();
            $t->string('digital_token', 48)->nullable()->unique();
            $t->timestamp('digital_sent_at')->nullable();
            $t->string('digital_error', 300)->nullable();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->json('digital_content')->nullable(); // {url, file, instructions}
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['auto_ship_tries', 'auto_ship_error', 'digital_token', 'digital_sent_at', 'digital_error']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('digital_content'));
    }
};
