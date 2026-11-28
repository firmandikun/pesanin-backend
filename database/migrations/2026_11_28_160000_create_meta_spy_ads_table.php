<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riset Iklan Meta: ads a seller saw in the Meta Ad Library (captured by the Pesanin Chrome extension), one row per
 * seller + ad_archive_id. Written by Laravel (MetaAdsSpyController::ingest) or the Go service (services/cmd/metaspy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_spy_ads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ad_archive_id', 40);
            $t->string('page_id', 40)->nullable();
            $t->string('page_name', 191)->nullable();
            $t->string('page_picture_url', 1000)->nullable();
            $t->unsignedInteger('collation_count')->default(1);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->json('platforms')->nullable();          // ["facebook","instagram","audience_network","messenger"]
            $t->string('title', 500)->nullable();
            $t->text('body')->nullable();
            $t->string('cta_text', 100)->nullable();
            $t->string('cta_type', 50)->nullable();
            $t->string('link_url', 1000)->nullable();
            $t->string('link_type', 20)->nullable();    // website | whatsapp | messenger | instagram | other
            $t->string('media_type', 20)->nullable();   // video | image | carousel
            $t->string('thumbnail_url', 1000)->nullable();
            $t->json('media_urls')->nullable();         // [{type, url, preview}]
            $t->string('score', 10)->default('TEST');   // WINNING | SCALE | TEST
            $t->string('search_query', 191)->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'ad_archive_id']);
            $t->index(['user_id', 'score']);
            $t->index(['user_id', 'page_id']);
            $t->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_spy_ads');
    }
};
