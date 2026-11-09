<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp Cloud API has no "read history" endpoint: every message arrives by webhook and is kept here.
        Schema::create('wa_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('phone_number_id', 40);           // the seller's WhatsApp business number (Meta id)
            $t->string('wa_id', 32);                     // the customer's WhatsApp number
            $t->string('contact_name')->nullable();
            $t->enum('direction', ['in', 'out']);
            $t->string('message_id', 128)->nullable()->unique();
            $t->string('type', 30)->default('text');
            $t->text('text')->nullable();
            $t->string('media_id', 128)->nullable();
            $t->string('status', 20)->nullable();        // out: sent / delivered / read / failed
            $t->boolean('read')->default(false);         // in: opened in Pesanin
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'phone_number_id', 'wa_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
    }
};
