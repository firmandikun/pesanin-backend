<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notification feed (bell in the header), e.g. "Pesanan baru".
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->string('title');
            $t->string('body')->nullable();
            $t->string('url')->nullable();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'id']);
        });

        // Browser Web Push subscriptions (VAPID): notifications even when the tab is closed.
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('endpoint', 500)->unique();
            $t->string('p256dh');
            $t->string('auth');
            $t->string('user_agent')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('app_notifications');
    }
};
