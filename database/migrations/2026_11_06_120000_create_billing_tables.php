<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('plan', 20)->default('gratis')->after('settings');
            $table->string('plan_billing', 10)->nullable()->after('plan');
            $table->timestamp('plan_expires_at')->nullable()->after('plan_billing');
            $table->boolean('plan_auto_renew')->default(true)->after('plan_expires_at');
        });

        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->string('plan', 20);
            $table->string('billing', 10);
            $table->unsignedInteger('amount');
            $table->unsignedInteger('tax');
            $table->unsignedInteger('total');
            $table->string('method', 30);
            $table->string('pay_code', 40)->nullable();
            $table->string('status', 15)->default('unpaid')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['plan', 'plan_billing', 'plan_expires_at', 'plan_auto_renew']));
    }
};
