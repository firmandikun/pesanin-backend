<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Background "cetak resi" jobs: the server downloads and formats many Shopee labels into one PDF to download. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awb_print_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 10);                 // selected | new | all
            $t->json('order_ids')->nullable();
            $t->json('booking_ids')->nullable();
            $t->string('status', 12)->default('queued'); // queued | running | done | failed
            $t->string('stage', 20)->nullable();     // checking | downloading | formatting
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('done')->default(0);
            $t->unsignedInteger('pages')->default(0);
            $t->string('file')->nullable();
            $t->json('skipped')->nullable();
            $t->json('errors')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awb_print_jobs');
    }
};
