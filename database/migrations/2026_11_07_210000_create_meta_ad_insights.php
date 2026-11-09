<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily Meta (Facebook/Instagram) ads performance per campaign, from the Marketing API.
        Schema::create('meta_ad_insights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ad_account_id', 40);
            $t->string('campaign_id', 40);
            $t->string('campaign_name')->nullable();
            $t->string('status', 30)->nullable();
            $t->string('objective', 50)->nullable();
            $t->date('date');
            $t->unsignedBigInteger('spend')->default(0);
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedInteger('purchases')->default(0);
            $t->unsignedBigInteger('purchase_value')->default(0);
            $t->unsignedInteger('messages')->default(0);
            $t->timestamps();
            $t->unique(['user_id', 'campaign_id', 'date']);
            $t->index(['user_id', 'date']);
        });
        // Synced spend rows carry the platform's campaign id so re-syncs update instead of duplicating.
        Schema::table('ad_spends', fn (Blueprint $t) => $t->string('external_id', 60)->nullable()->after('campaign'));
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ad_insights');
        Schema::table('ad_spends', fn (Blueprint $t) => $t->dropColumn('external_id'));
    }
};
