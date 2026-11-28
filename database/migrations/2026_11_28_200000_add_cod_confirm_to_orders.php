<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Konfirmasi COD otomatis: WhatsApp "balas YA" before a COD parcel is shipped. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            // null (not handled) · skipped (low risk) · waiting · confirmed · declined · no_reply · failed (WA not sent)
            $t->string('cod_confirm_status', 12)->nullable()->after('payment_status')->index();
            $t->timestamp('cod_confirm_sent_at')->nullable()->after('cod_confirm_status');
            $t->timestamp('cod_confirmed_at')->nullable()->after('cod_confirm_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['cod_confirm_status']);
            $t->dropColumn(['cod_confirm_status', 'cod_confirm_sent_at', 'cod_confirmed_at']);
        });
    }
};
