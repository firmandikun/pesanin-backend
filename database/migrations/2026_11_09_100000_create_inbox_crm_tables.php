<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Inbox collaboration (assign, status, labels, notes, quick replies) and a simple customer CRM. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->json('labels')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('opt_in')->default(false);
            $t->string('source', 20)->nullable();
            $t->timestamps();
            $t->index(['user_id', 'phone']);
        });
        Schema::create('chat_threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('channel', 20);
            $t->string('source_id', 64);
            $t->string('conversation_id', 191);
            $t->string('name')->nullable();
            $t->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('team_members')->nullOnDelete();
            $t->string('status', 10)->default('open');
            $t->timestamp('done_at')->nullable();
            $t->json('labels')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'channel', 'source_id', 'conversation_id'], 'chat_threads_key');
        });
        Schema::create('chat_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_thread_id')->constrained()->cascadeOnDelete();
            $t->string('author')->nullable();
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('chat_labels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 40);
            $t->string('color', 20)->default('slate');
            $t->timestamps();
            $t->unique(['user_id', 'name']);
        });
        Schema::create('quick_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('shortcut', 40);
            $t->string('category', 60)->nullable();
            $t->text('body');
            $t->timestamps();
            $t->unique(['user_id', 'shortcut']);
        });
    }

    public function down(): void
    {
        foreach (['quick_replies', 'chat_labels', 'chat_notes', 'chat_threads', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
