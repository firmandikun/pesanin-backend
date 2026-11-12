<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\MarketplaceShop;
use App\Models\User;
use App\Support\Analytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pengeluaran: operational costs cut laba bersih, stock/asset purchases are cash-only, ads are listed alongside. */
class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_crud_listing_and_profit(): void
    {
        $user = User::factory()->create();
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 5, 'shop_name' => 'Toko A', 'status' => 'active']);
        Sanctum::actingAs($user);
        $today = today()->toDateString();

        $id = $this->postJson('/api/expenses', ['spent_on' => $today, 'category' => 'operational', 'description' => 'Gaji admin', 'amount' => 300000, 'marketplace_shop_id' => $shop->id, 'payment_method' => 'transfer'])
            ->assertCreated()->json('id');
        $this->postJson('/api/expenses', ['spent_on' => $today, 'category' => 'operational', 'description' => 'Sewa gudang', 'amount' => 200000])->assertCreated();
        $this->postJson('/api/expenses', ['spent_on' => $today, 'category' => 'inventory', 'description' => 'Kulakan stok', 'amount' => 1000000, 'marketplace_shop_id' => $shop->id])->assertCreated();
        $this->postJson('/api/expenses', ['spent_on' => $today, 'category' => 'ads', 'description' => 'x', 'amount' => 1])->assertUnprocessable();
        $this->postJson('/api/expenses', ['spent_on' => now()->addDay()->toDateString(), 'category' => 'operational', 'description' => 'x', 'amount' => 1])->assertUnprocessable();
        AdSpend::create(['user_id' => $user->id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $shop->id, 'spent_on' => $today, 'amount' => 100000, 'synced' => true]);

        $r = $this->getJson("/api/expenses?shop_id={$shop->id}")->assertOk();
        $r->assertJsonPath('summary.total', 300000 + 1000000 + 111000)
            ->assertJsonPath('summary.affects_profit', 300000 + 111000)
            ->assertJsonPath('summary.cash_only', 1000000);
        $this->assertCount(3, $r->json('data'));
        $this->assertCount(1, $this->getJson("/api/expenses?shop_id={$shop->id}&category=ads")->json('data'));

        // Shop profit: shop opex only. Overall: shop + business-wide opex. Stock purchases never cut profit.
        $shopP = Analytics::profit($user, today()->startOfDay(), today()->endOfDay(), 'shopee', $shop->id)['summary'];
        $this->assertSame([300000, -300000 - 111000], [$shopP['opex'], $shopP['net_profit']]);
        $all = Analytics::profit($user, today()->startOfDay(), today()->endOfDay())['summary'];
        $this->assertSame(500000, $all['opex']);

        $this->putJson("/api/expenses/{$id}", ['spent_on' => $today, 'category' => 'operational', 'description' => 'Gaji admin', 'amount' => 350000])->assertOk();
        $this->assertSame(550000, Analytics::profit($user, today()->startOfDay(), today()->endOfDay())['summary']['opex']);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->deleteJson("/api/expenses/{$id}")->assertNotFound();
        $this->postJson('/api/expenses', ['spent_on' => $today, 'category' => 'operational', 'description' => 'x', 'amount' => 1, 'marketplace_shop_id' => $shop->id])->assertUnprocessable();
        Sanctum::actingAs($user);
        $this->deleteJson("/api/expenses/{$id}")->assertNoContent();
    }
}
