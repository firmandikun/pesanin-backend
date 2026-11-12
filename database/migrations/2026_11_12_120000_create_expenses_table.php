<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pengeluaran: operational costs (cut profit) and cash-only outflows like stock purchases and assets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null = a business-wide cost not tied to one shop.
            $table->foreignId('marketplace_shop_id')->nullable()->constrained()->nullOnDelete();
            $table->date('spent_on');
            $table->string('category', 20); // operational | inventory | asset
            $table->string('description', 150);
            $table->unsignedBigInteger('amount');
            $table->string('payment_method', 20)->nullable();
            $table->string('vendor', 80)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
