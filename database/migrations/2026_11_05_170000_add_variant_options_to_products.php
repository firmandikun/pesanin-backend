<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('variant_options')->nullable()->after('variants');
            $table->foreignId('responsible_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('bump_items')->nullable()->after('bump_price');
            $table->string('village')->nullable()->after('district');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['bump_items', 'village']));
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
            $table->dropColumn('variant_options');
        });
    }
};
