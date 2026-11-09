<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RajaOngkir (Komerce) destinations are kelurahan-level: keep the kelurahan name and its id.
        Schema::table('regions', function (Blueprint $t) {
            $t->string('subdistrict', 100)->nullable()->after('district');
            $t->unsignedBigInteger('rajaongkir_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('regions', function (Blueprint $t) {
            $t->dropUnique(['rajaongkir_id']);
            $t->dropColumn(['subdistrict', 'rajaongkir_id']);
        });
    }
};
