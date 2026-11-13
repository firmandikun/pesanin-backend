<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shopee return / refund requests (Seller Centre → Pengembalian/Pembatalan). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $table->string('return_sn', 40);
            $table->string('order_sn', 40)->nullable();
            $table->string('status', 24); // REQUESTED | PROCESSING | ACCEPTED | JUDGING | SELLER_DISPUTE | CANCELLED | CLOSED
            $table->string('reason', 60)->nullable();
            $table->string('text_reason', 500)->nullable();
            $table->unsignedBigInteger('refund_amount')->default(0);
            $table->string('buyer_username', 80)->nullable();
            $table->string('tracking_number', 60)->nullable();
            $table->json('items')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_shop_id', 'return_sn']);
            $table->index(['marketplace_shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_returns');
    }
};
