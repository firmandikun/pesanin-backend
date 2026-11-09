<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_deletion_requests', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('source', 20)->default('meta');
            $t->string('external_user_id')->nullable();
            $t->unsignedInteger('deleted_integrations')->default(0);
            $t->string('status', 20)->default('completed');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};
