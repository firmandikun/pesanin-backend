<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopee "Pengiriman Kilat" reservations (bookings): parcels Shopee asks the seller to send, created by Shopee
 * rather than by a buyer. They sit in Seller Centre's "Perlu Dikirim" but are not orders and carry no revenue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $table->string('booking_sn', 40);
            $table->string('order_sn', 40)->nullable(); // set once Shopee matches the booking to a buyer order
            $table->string('booking_status', 20); // READY_TO_SHIP | PROCESSED | SHIPPED | CANCELLED | MATCHED
            $table->string('shipping_carrier', 80)->nullable();
            $table->string('tracking_number', 60)->nullable();
            $table->json('items')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();
            $table->unique(['marketplace_shop_id', 'booking_sn']);
            $table->index(['marketplace_shop_id', 'booking_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_bookings');
    }
};
