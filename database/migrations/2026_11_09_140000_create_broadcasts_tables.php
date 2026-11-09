<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** WhatsApp broadcasts: one approved template sent to many customers, paced by a scheduler. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('phone_number_id', 40);
            $t->string('template_name', 512);
            $t->string('template_language', 15);
            $t->string('template_category', 20)->nullable();
            $t->text('body')->nullable();               // template body, for previews and the chat history
            $t->json('variables')->nullable();          // [{mode: text|name, value}] for {{1}}, {{2}}…
            $t->json('audience')->nullable();           // how recipients were picked (for display)
            $t->string('status', 15)->default('scheduled'); // scheduled | sending | done | cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['status', 'scheduled_at']);
        });
        Schema::create('broadcast_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name')->nullable();
            $t->string('phone', 30);
            $t->string('status', 12)->default('queued'); // queued | sent | delivered | read | failed | cancelled
            $t->string('message_id', 128)->nullable()->index();
            $t->string('error', 300)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
