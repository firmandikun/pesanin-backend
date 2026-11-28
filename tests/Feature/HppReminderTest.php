<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\HppReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Pengingat HPP kosong + best sellers first in the bulk HPP dialog. */
class HppReminderTest extends TestCase
{
    use RefreshDatabase;

    private function seller(): array
    {
        $user = User::factory()->create();
        $shop = MarketplaceShop::create(['user_id' => $user->id, 'platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'Toko A', 'status' => 'active']);
        $mk = fn (int $item, string $name, ?string $sku, ?int $cost) => MarketplaceListing::create([
            'user_id' => $user->id, 'marketplace_shop_id' => $shop->id, 'item_id' => $item, 'model_id' => 0, 'name' => $name, 'sku' => $sku,
            'price' => 50000, 'stock' => 10, 'cost_price' => $cost,
        ]);
        $mk(101, 'Kabel Serabut', 'KBL-1', null);   // sold 5, no HPP
        $mk(102, 'Tutup Knalpot', 'TTP-1', null);   // sold 2, no HPP
        $mk(103, 'Saklar Lampu', 'SKL-1', 20000);   // sold 9, has HPP
        $mk(104, 'Helm Retro', 'HLM-1', null);      // not sold
        foreach ([[101, 3], [101, 2], [102, 2], [103, 9]] as $i => [$item, $qty]) {
            $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $shop->id, 'external_order_sn' => "SN{$i}",
                'external_status' => 'COMPLETED', 'customer_name' => 'B', 'quantity' => $qty, 'unit_price' => 50000, 'subtotal' => 50000 * $qty, 'total' => 50000 * $qty,
                'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'completed', 'shipping_status' => 'delivered',
                'items' => [['item_id' => $item, 'model_id' => 0, 'qty' => $qty, 'price' => 50000]]]);
            $o->user_id = $user->id;
            $o->created_at = now()->subDays(2);
            $o->save();
        }

        return [$user, $shop];
    }

    public function test_lists_sold_products_without_hpp_best_sellers_first(): void
    {
        [$user] = $this->seller();
        $this->assertSame([['Kabel Serabut', 5], ['Tutup Knalpot', 2]], array_map(fn ($r) => [$r['name'], $r['units']], HppReminder::missing($user)));
    }

    public function test_weekly_reminder_once_and_switchable(): void
    {
        [$user] = $this->seller();
        $this->artisan('marketplace:hpp-reminder')->expectsOutputToContain('Pengingat HPP terkirim: 1')->assertSuccessful();
        $n = AppNotification::where('user_id', $user->id)->sole();
        $this->assertSame('2 produk terjual minggu ini belum ada HPP (7 pcs)', $n->title);
        $this->assertStringContainsString('Kabel Serabut [KBL-1] · terjual 5', $n->body);
        $this->assertSame(HppReminder::URL, $n->url);
        $this->assertSame(0, HppReminder::notify($user), 'once per week');

        $other = $this->seller()[0];
        $other->update(['settings' => ['notify' => ['missing_hpp' => false]]]);
        $this->assertSame(0, HppReminder::notify($other->fresh()));
    }

    public function test_bulk_cost_groups_include_units_sold(): void
    {
        [$user] = $this->seller();
        Sanctum::actingAs($user);
        $groups = collect($this->getJson('/api/marketplace/listing-costs?by=sku')->assertOk()->json('groups'))->pluck('sold_30d', 'key');
        $this->assertSame(['HLM-1' => 0, 'KBL-1' => 5, 'SKL-1' => 9, 'TTP-1' => 2], $groups->sortKeys()->all());
    }

    public function test_cek_margin_still_counts_units_after_refactor(): void
    {
        [$user] = $this->seller();
        Sanctum::actingAs($user);
        $r = $this->getJson('/api/marketplace/listings/margins')->assertOk()->json();
        $this->assertSame(3, $r['summary']['no_hpp']);
        $this->assertSame([['SKL-1', 9, 50000, 20000]], array_map(fn ($x) => [$x['sku'], $x['units_30d'], $x['price'], $x['hpp']], $r['rows']));
    }
}
