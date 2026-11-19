<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real shipping for dropship: the catalog product ships from its warehouse region, and an adopted product
 * remembers its catalog source (so checkout prices ongkir from the warehouse and never offers COD).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dropship_products', function (Blueprint $table) {
            $table->foreignId('warehouse_region_id')->nullable()->after('warehouse_city')->constrained('regions')->nullOnDelete();
        });
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('dropship_product_id')->nullable()->constrained('dropship_products')->nullOnDelete();
            $table->foreignId('origin_region_id')->nullable()->constrained('regions')->nullOnDelete();
        });

        // Existing data: link adopted products to their catalog source and drop COD everywhere in dropship.
        $noCod = fn ($json) => json_encode(array_values(array_diff(json_decode((string) $json, true) ?: [], ['cod'])) ?: ['bank_transfer', 'epayment']);
        foreach (DB::table('dropship_products')->get(['id', 'payment_methods']) as $p) {
            DB::table('dropship_products')->where('id', $p->id)->update(['payment_methods' => $noCod($p->payment_methods)]);
        }
        foreach (DB::table('dropship_adoptions')->get(['id', 'product_id', 'dropship_product_id', 'payment_methods']) as $a) {
            DB::table('dropship_adoptions')->where('id', $a->id)->update(['payment_methods' => $noCod($a->payment_methods)]);
            if ($a->product_id) {
                $pm = DB::table('products')->where('id', $a->product_id)->value('payment_methods');
                DB::table('products')->where('id', $a->product_id)->update(['dropship_product_id' => $a->dropship_product_id, 'payment_methods' => $noCod($pm)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dropship_product_id');
            $table->dropConstrainedForeignId('origin_region_id');
        });
        Schema::table('dropship_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_region_id');
        });
    }
};
