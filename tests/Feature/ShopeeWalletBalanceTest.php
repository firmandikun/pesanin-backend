<?php

namespace Tests\Feature;

use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Marketplace → Saldo: Shopee balance from Shopee's payment API with totals; Pesanin's own Saldo leaves Shopee out. */
class ShopeeWalletBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_balance_from_api_and_pesanin_balance_excludes_shopee(): void
    {
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $user = User::factory()->create();
        $shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 1, 'shop_name' => 'M storeku', 'status' => 'active', 'is_demo' => false,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $shop->user_id = $user->id;
        $shop->save();
        foreach ([['shopee', 90000000], ['website', 150000]] as [$channel, $total]) {
            $o = new Order(['channel' => $channel, 'source' => $channel === 'shopee' ? 'marketplace' : 'web', 'customer_name' => 'B', 'quantity' => 1,
                'unit_price' => $total, 'subtotal' => $total, 'total' => $total, 'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
                'status' => 'processing', 'shipping_status' => 'not_shipped', 'paid_at' => now()]);
            $o->user_id = $user->id;
            $o->save();
        }
        Http::fake(function ($req) {
            if (str_contains($req->url(), 'get_wallet_transaction_list')) {
                return Http::response(['response' => ['more' => false, 'transaction_list' => [
                    ['transaction_type' => 'ESCROW_VERIFIED_ADD', 'amount' => 50000, 'current_balance' => 1250000, 'create_time' => 1700000000, 'order_sn' => 'A1', 'status' => 'COMPLETED'],
                    ['transaction_type' => 'WITHDRAWAL_CREATED', 'amount' => -300000, 'current_balance' => 950000, 'create_time' => 1700005000, 'status' => 'COMPLETED'],
                ]]]);
            }
            if (str_contains($req->url(), 'get_income_overview')) {
                return Http::response(['response' => ['total_income' => ['pending_amount' => 420000.5, 'released_amount' => 8000000]]]);
            }

            return Http::response(['response' => []]);
        });

        $res = $this->actingAs($user)->getJson('/api/balance')->assertOk()->json();
        $this->assertSame(150000, $res['available']); // the Shopee order is not in Pesanin's balance
        $this->assertArrayNotHasKey('shopee', $res); // Shopee money lives on Marketplace → Saldo

        $mp = $this->getJson('/api/marketplace/balance')->assertOk()->json();
        $this->assertSame(['balance' => 950000, 'pending' => 420001, 'released' => 8000000], $mp['totals']);
        $w = $mp['shops'][0];
        $this->assertSame('M storeku', $w['shop_name']);
        $this->assertSame(950000, $w['balance']); // current_balance of the newest wallet transaction
        $this->assertSame(420001, $w['pending']);
        $this->assertSame(8000000, $w['released']);
        $this->assertSame('Penarikan dibuat', $w['transactions'][0]['type']);
        $this->assertNull($w['error']);
    }
}
