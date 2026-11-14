<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the Shopee air waybill was last produced (printed manually or sent by the schedule), so the scheduled
        // Telegram send only includes labels that haven't been printed yet.
        Schema::table('orders', fn (Blueprint $t) => $t->timestamp('awb_printed_at')->nullable());
        Schema::table('shopee_bookings', fn (Blueprint $t) => $t->timestamp('awb_printed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('awb_printed_at'));
        Schema::table('shopee_bookings', fn (Blueprint $t) => $t->dropColumn('awb_printed_at'));
    }
};
