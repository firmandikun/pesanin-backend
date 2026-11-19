<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Buyer reviews of the seller's Shopee products, synced from v2.product.get_comment, answered from Pesanin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('comment_id');
            $table->unsignedBigInteger('item_id')->index();
            $table->string('order_sn', 40)->nullable();
            $table->string('buyer')->nullable();
            $table->unsignedTinyInteger('rating')->index();
            $table->text('comment')->nullable();
            $table->json('media')->nullable();
            $table->boolean('hidden')->default(false);
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable()->index();
            $table->string('reply_error')->nullable();
            $table->timestamp('reviewed_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['marketplace_shop_id', 'comment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_reviews');
    }
};
