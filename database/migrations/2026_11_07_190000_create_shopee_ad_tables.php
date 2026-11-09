<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopee product-ads campaigns (Iklan Produk) and the items each one promotes.
        Schema::create('shopee_ad_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('marketplace_shop_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('campaign_id');
            $t->string('name')->nullable();
            $t->string('ad_type', 30)->nullable();
            $t->string('status', 30)->nullable();
            $t->string('placement', 30)->nullable();
            $t->unsignedBigInteger('budget')->nullable();
            $t->json('item_ids')->nullable();
            $t->timestamps();
            $t->unique(['marketplace_shop_id', 'campaign_id']);
        });
        // Daily performance per campaign.
        Schema::create('shopee_ad_stats', function (Blueprint $t) {
            $t->id();
            $t->foreignId('shopee_ad_campaign_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->unsignedInteger('impressions')->default(0);
            $t->unsignedInteger('clicks')->default(0);
            $t->unsignedBigInteger('expense')->default(0);
            $t->unsignedBigInteger('gmv')->default(0);
            $t->unsignedInteger('orders')->default(0);
            $t->unsignedInteger('items_sold')->default(0);
            $t->timestamps();
            $t->unique(['shopee_ad_campaign_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_ad_stats');
        Schema::dropIfExists('shopee_ad_campaigns');
    }
};
