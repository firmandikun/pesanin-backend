<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Produk Shopee → filter "Stok habis" / "Stok tersedia" (per variation, same rule as the "Stok habis" card). */
class ShopeeListingStockFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_filter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'T', 'status' => 'active', 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        foreach ([[1, 0, 'A', 0], [1, 1, 'A', 5], [2, 0, 'B', 3], [3, 0, 'C', 0]] as [$item, $model, $name, $stock]) {
            $l = new MarketplaceListing(['marketplace_shop_id' => $shop->id, 'item_id' => $item, 'model_id' => $model, 'name' => $name, 'price' => 1000, 'stock' => $stock, 'status' => 'NORMAL']);
            $l->user_id = $user->id;
            $l->save();
        }
        $rows = fn (string $q) => collect($this->getJson("/api/marketplace/listings?{$q}")->assertOk()->json('listings.data'))
            ->map(fn ($r) => $r['name'].$r['model_id'])->sort()->values()->all();

        $this->assertSame(['A0', 'C0'], $rows('stock=out'));
        $this->assertSame(['A1', 'B0'], $rows('stock=in'));
        $this->assertSame(['A0', 'A1', 'B0', 'C0'], $rows(''));
        $this->assertSame(2, $this->getJson('/api/marketplace/listings?stock=out')->json('stats.out_of_stock'));
    }
}
