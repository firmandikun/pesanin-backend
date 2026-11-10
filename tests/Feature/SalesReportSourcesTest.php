<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesReportSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_separates_social_media_and_marketplace_shops(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shop = MarketplaceShop::create([
            'user_id' => $user->id,
            'platform' => 'shopee',
            'shop_id' => 228016965,
            'shop_name' => 'Toko Shopee Utama',
            'region' => 'ID',
            'status' => 'active',
        ]);

        $social = new Order([
            'code' => 'WEB-1', 'channel' => 'website',
            'utm_source' => 'instagram', 'quantity' => 2, 'total' => 120000,
            'payment_status' => 'paid', 'is_abandoned' => false,
        ]);
        $social->user()->associate($user);
        $social->save();
        $marketplace = new Order([
            'code' => 'SHP-1', 'channel' => 'shopee',
            'source' => 'marketplace', 'marketplace_shop_id' => $shop->id,
            'external_order_sn' => 'ORDER-SHOPEE-1', 'quantity' => 1, 'total' => 85000,
            'payment_status' => 'unpaid', 'is_abandoned' => false,
        ]);
        $marketplace->user()->associate($user);
        $marketplace->save();

        $response = $this->getJson('/api/reports/sales?from='.today()->toDateString().'&to='.today()->toDateString())->assertOk();

        $response->assertJsonPath('sources.0.type', 'social_media')
            ->assertJsonPath('sources.0.label', 'Media Sosial · Iklan Meta (FB/IG)')
            ->assertJsonPath('sources.0.net', 120000)
            ->assertJsonPath('sources.1.type', 'marketplace')
            ->assertJsonPath('sources.1.shop_name', 'Toko Shopee Utama')
            ->assertJsonPath('sources.1.label', 'Shopee · Toko Shopee Utama')
            ->assertJsonPath('sources.1.net', 0);
    }
}
