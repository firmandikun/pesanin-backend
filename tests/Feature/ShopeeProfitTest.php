<?php

namespace Tests\Feature;

use App\Models\AdSpend;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Shopee\ShopeeSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Shopee laba per pesanan: income estimate → final, fee lines, cancelled/returned orders, Shopee Ads PPN. */
class ShopeeProfitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MarketplaceShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->user = User::factory()->create();
        $this->shop = new MarketplaceShop(['platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'Toko', 'status' => 'active',
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $this->shop->user_id = $this->user->id;
        $this->shop->save();
    }

    private function detail(string $status): array
    {
        return ['order_sn' => 'SN1', 'order_status' => $status, 'create_time' => now()->timestamp, 'pay_time' => now()->timestamp,
            'item_list' => [['item_id' => 1, 'item_name' => 'Cover knalpot', 'model_id' => 0, 'model_quantity_purchased' => 1, 'model_discounted_price' => 22850]],
            'total_amount' => 26350];
    }

    private function income(int $escrow, int $actualShipping): array
    {
        return ['response' => ['order_income' => [
            'escrow_amount' => $escrow, 'commission_fee' => 1885, 'service_fee' => 1257, 'seller_order_processing_fee' => 1250,
            'actual_shipping_fee' => $actualShipping, 'shopee_shipping_rebate' => 3500, 'withholding_tax' => 114,
        ]]];
    }

    public function test_income_is_estimated_when_paid_and_refreshed_until_final(): void
    {
        $escrow = 18344;
        Http::fake(['*get_escrow_detail*' => function () use (&$escrow) {
            return Http::response($this->income($escrow, $escrow === 18344 ? 3500 : 4500));
        }]);
        $sync = new ShopeeSync;

        $order = $sync->upsertOrder($this->shop, $this->detail('READY_TO_SHIP'));
        $this->assertSame(18344, $order->escrow_amount);
        $this->assertFalse($order->escrow_final);
        $lines = collect($order->fee_breakdown)->pluck('amount', 'label');
        $this->assertSame(-1885, $lines['Biaya Admin']);
        $this->assertSame(3500, $lines['Subsidi Ongkir Shopee']);
        $this->assertSame(18344 - 22850, array_sum(array_column($order->fee_breakdown, 'amount')), 'lines add up to escrow - revenue');

        // Same status again: no new call. Completed: the final (lower) income replaces the estimate.
        $sync->upsertOrder($this->shop, $this->detail('READY_TO_SHIP'));
        Http::assertSentCount(1);
        $escrow = 17344;
        $order = $sync->upsertOrder($this->shop, $this->detail('COMPLETED'));
        $this->assertSame(17344, $order->escrow_amount);
        $this->assertTrue($order->escrow_final);

        Sanctum::actingAs($this->user);
        $row = $this->getJson("/api/marketplace/shops/{$this->shop->id}/transactions")->assertOk()->json('transactions.data.0');
        $this->assertSame([22850, 5506, 17344, true], [$row['revenue'], $row['fees'], $row['net_revenue'], $row['fee_final']]);
        $this->assertNotEmpty($row['fee_breakdown']);

        // Ekspor: one column per Shopee fee line, Excel-friendly.
        $csv = $this->get("/api/marketplace/shops/{$this->shop->id}/transactions/export")->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        [$head, $line] = array_map(fn ($l) => str_getcsv($l, ';'), array_slice(explode("\n", trim(substr($csv, 3))), 0, 2));
        $this->assertContains('Biaya Admin', $head);
        $this->assertSame('-1885', $line[array_search('Biaya Admin', $head)]);
        $this->assertSame('17344', $line[array_search('Pendapatan Bersih', $head)]);
    }

    public function test_cancelled_orders_are_zero_and_returns_count_as_a_loss(): void
    {
        $make = function (string $sn, string $status, string $ext, ?int $escrow) {
            $o = new Order(['code' => $sn, 'channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id,
                'external_order_sn' => $sn, 'external_status' => $ext, 'status' => $status, 'payment_status' => 'paid', 'payment_method' => 'marketplace',
                'quantity' => 1, 'subtotal' => 50000, 'total' => 55000, 'escrow_amount' => $escrow, 'escrow_final' => true,
                'items' => [['item_id' => 1, 'model_id' => 0, 'name' => 'X', 'qty' => 1, 'price' => 50000]]]);
            $o->user_id = $this->user->id;
            $o->save();
        };
        $make('OK', 'completed', 'COMPLETED', 44000);
        $make('BATAL', 'cancelled', 'CANCELLED', null);
        $make('RETUR', 'refunded', 'TO_RETURN', -9000);

        Sanctum::actingAs($this->user);
        $rows = collect($this->getJson("/api/marketplace/shops/{$this->shop->id}/transactions")->json('transactions.data'))->keyBy('order_sn');
        $this->assertSame([0, 0, 0], [$rows['BATAL']['gmv'], $rows['BATAL']['profit'], $rows['BATAL']['cogs']]);
        $this->assertSame(-9000, $rows['RETUR']['profit']);

        $s = Analytics::profit($this->user, now()->subDay(), now()->addDay(), 'shopee', $this->shop->id)['summary'];
        $this->assertSame(50000, $s['revenue'], 'returned order is not omzet');
        $this->assertSame(50000 - 44000 + 9000, $s['fees'], 'return loss is a deduction');
    }

    public function test_estimate_without_shopee_income_includes_order_processing_fee(): void
    {
        $make = function (string $sn, ?int $escrow) {
            $o = new Order(['code' => $sn, 'channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id,
                'external_order_sn' => $sn, 'external_status' => 'READY_TO_SHIP', 'status' => 'processing', 'payment_status' => 'paid', 'payment_method' => 'marketplace',
                'quantity' => 1, 'subtotal' => 100000, 'total' => 100000, 'escrow_amount' => $escrow,
                'items' => [['item_id' => 1, 'model_id' => 0, 'name' => 'X', 'qty' => 1, 'price' => 100000]]]);
            $o->user_id = $this->user->id;
            $o->save();
        };
        $make('KNOWN', 88750); // 10% + Rp1.250 → the shop's % rate is 10%
        $make('NEW', null);

        Sanctum::actingAs($this->user);
        $row = collect($this->getJson("/api/marketplace/shops/{$this->shop->id}/transactions")->json('transactions.data'))->firstWhere('order_sn', 'NEW');
        $this->assertSame(10000 + 1250, $row['fees']);
        $this->assertFalse($row['fee_final']);
        $this->assertSame(-1250, collect($row['fee_breakdown'])->firstWhere('label', 'Biaya Pemrosesan Pesanan')['amount']);
    }

    public function test_shopee_ads_spend_includes_ppn(): void
    {
        AdSpend::create(['user_id' => $this->user->id, 'platform' => 'shopee_ads', 'marketplace_shop_id' => $this->shop->id,
            'spent_on' => today()->toDateString(), 'amount' => 100000, 'synced' => true, 'campaign' => 'Shopee Ads (otomatis)']);
        AdSpend::create(['user_id' => $this->user->id, 'platform' => 'meta', 'spent_on' => today()->toDateString(), 'amount' => 50000]);

        $p = Analytics::profit($this->user, today()->startOfDay(), today()->endOfDay());
        $this->assertSame(111000 + 50000, $p['summary']['ads']);

        $this->shop->update(['ads_ppn' => 0]);
        $this->assertSame(150000, Analytics::profit($this->user, today()->startOfDay(), today()->endOfDay())['summary']['ads']);
    }

    public function test_transaction_deducts_ad_cost_per_conversion_when_product_is_advertised(): void
    {
        // Product 101 is advertised (Biaya Iklan = 125552, Konversi = 33 -> Biaya per Konversi = 3804.61 -> 3805)
        // Product 202 is NOT advertised
        $camp = new \App\Models\ShopeeAdCampaign([
            'marketplace_shop_id' => $this->shop->id,
            'campaign_id' => 701,
            'name' => 'Iklan Cover Knalpot',
            'ad_type' => 'manual',
            'status' => 'ongoing',
            'bidding_method' => 'auto',
            'item_ids' => [101],
        ]);
        $camp->user_id = $this->user->id;
        $camp->save();
        \App\Models\ShopeeAdStat::create([
            'shopee_ad_campaign_id' => $camp->id,
            'date' => today()->toDateString(),
            'impressions' => 6600,
            'clicks' => 210,
            'expense' => 125552,
            'gmv' => 815200,
            'orders' => 33,
            'items_sold' => 34,
        ]);

        $o1 = new Order([
            'code' => 'ORD-ADS', 'channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id,
            'external_order_sn' => 'ORD-ADS', 'external_status' => 'COMPLETED', 'status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'marketplace',
            'quantity' => 1, 'subtotal' => 24350, 'total' => 24350, 'escrow_amount' => 20000, 'escrow_final' => true,
            'items' => [['item_id' => 101, 'model_id' => 0, 'name' => 'Cover Knalpot Airblade', 'qty' => 1, 'price' => 24350]],
        ]);
        $o1->user_id = $this->user->id;
        $o1->save();

        $o2 = new Order([
            'code' => 'ORD-NOADS', 'channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id,
            'external_order_sn' => 'ORD-NOADS', 'external_status' => 'COMPLETED', 'status' => 'completed', 'payment_status' => 'paid', 'payment_method' => 'marketplace',
            'quantity' => 1, 'subtotal' => 50000, 'total' => 50000, 'escrow_amount' => 42000, 'escrow_final' => true,
            'items' => [['item_id' => 202, 'model_id' => 0, 'name' => 'Produk Tanpa Iklan', 'qty' => 1, 'price' => 50000]],
        ]);
        $o2->user_id = $this->user->id;
        $o2->save();

        Sanctum::actingAs($this->user);
        $res = $this->getJson("/api/marketplace/shops/{$this->shop->id}/transactions")->assertOk()->json();
        $rows = collect($res['transactions']['data'])->keyBy('order_sn');

        // Advertised product order:
        $adRow = $rows['ORD-ADS'];
        $this->assertTrue($adRow['is_advertised']);
        $this->assertTrue($adRow['items'][0]['is_advertised']);
        $this->assertEqualsWithDelta(3804.61, $adRow['items'][0]['ad_cost_per_conversion'], 0.01);
        $this->assertSame(3805, $adRow['ad_cost']);
        $this->assertSame(20000, $adRow['gross_profit']);
        $this->assertSame(20000 - 3805, $adRow['profit']);

        // Non-advertised product order:
        $noAdRow = $rows['ORD-NOADS'];
        $this->assertFalse($noAdRow['is_advertised']);
        $this->assertFalse($noAdRow['items'][0]['is_advertised']);
        $this->assertSame(0, $noAdRow['ad_cost']);
        $this->assertSame(42000, $noAdRow['profit']);

        // Summary includes total ad_cost deducted across orders:
        $this->assertSame(3805, $res['summary']['ads']);
        $this->assertSame(20000 + 42000 - 3805, $res['summary']['profit']);
    }
}
