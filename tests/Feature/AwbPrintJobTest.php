<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AwbPrintJob;
use App\Models\MarketplaceShop;
use App\Models\Order;
use App\Models\User;
use App\Support\AwbPrintJobs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** Cetak resi di latar belakang: chunks of 50, documents requested before downloading, one formatted PDF. */
class AwbPrintJobTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private MarketplaceShop $shop;

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);
        $this->seller = User::factory()->create();
        $this->shop = MarketplaceShop::create(['user_id' => $this->seller->id, 'platform' => 'shopee', 'shop_id' => 9, 'shop_name' => 'M storeku', 'status' => 'active', 'is_demo' => false,
            'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour(), 'refresh_expires_at' => now()->addDays(30)]);
        $pdf = file_get_contents(base_path('tests/Fixtures/awb-sample.pdf')); // 3 labels: 26110{0,1,2}ABCDEF
        Http::fake(function ($req) use ($pdf) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            $this->calls[] = basename($path).':'.count($req['order_list'] ?? []);

            return match (basename($path)) {
                'download_shipping_document' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
                'get_shipping_document_result' => Http::response(['response' => ['result_list' => [['status' => 'READY']]]]),
                default => Http::response(['response' => ['order_list' => [], 'result_list' => []]]),
            };
        });
    }

    private function orders(int $n, string $courier = 'SPX Hemat', string $prefix = '26110'): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $o = new Order(['channel' => 'shopee', 'source' => 'marketplace', 'marketplace_shop_id' => $this->shop->id, 'external_order_sn' => $prefix.$i.'ABCDEF',
                'external_status' => 'PROCESSED', 'tracking_number' => "SPX{$prefix}{$i}", 'courier' => $courier, 'customer_name' => 'B', 'quantity' => 1, 'unit_price' => 1, 'subtotal' => 1, 'total' => 1,
                'payment_method' => 'marketplace', 'payment_status' => 'paid', 'status' => 'processing', 'shipping_status' => 'not_shipped',
                'items' => [['item_id' => 1, 'model_id' => 0, 'qty' => 1, 'name' => 'Cover Tutup Knalpot', 'item_sku' => 'CTKA', 'model_sku' => 'CTKA-AIR']]]);
            $o->user_id = $this->seller->id;
            $o->save();
            $ids[] = $o->id;
        }

        return $ids;
    }

    public function test_selected_labels_become_one_pdf_to_download(): void
    {
        $ids = $this->orders(3);
        Sanctum::actingAs($this->seller);
        $job = $this->postJson('/api/labels/shopee/print-jobs', ['scope' => 'selected', 'order_ids' => $ids])->assertCreated()->json();
        $this->assertSame(['done', 3, 3], [$job['status'], $job['total'], $job['done']]);
        $this->assertTrue($job['has_file']);
        $this->assertSame(3, Order::whereNotNull('awb_printed_at')->count());
        $n = AppNotification::where('user_id', $this->seller->id)->where('type', 'print')->sole();
        $this->assertSame('3 resi Shopee siap dicetak', $n->title);
        $this->assertSame("/marketplace/orders?print_job={$job['id']}", $n->url);

        $file = $this->get("/api/labels/shopee/print-jobs/{$job['id']}/file")->assertOk();
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));

        $node = config('services.node.binary') ?: (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/usr/bin', '/opt/homebrew/bin']);
        if ($node && is_dir(base_path('scripts/awb/node_modules'))) {
            $this->assertSame(3, $job['pages'], 'formatted by the Node formatter (same code as the browser)');
        }

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/labels/shopee/print-jobs/{$job['id']}")->assertNotFound();
    }

    public function test_big_batches_are_chunked_and_requested_before_downloading(): void
    {
        $this->orders(120);
        $job = AwbPrintJobs::start($this->seller, 'new');
        $this->assertSame([120, 120], [$job->total, $job->done]);

        $creates = array_values(array_filter($this->calls, fn ($c) => str_starts_with($c, 'create_shipping_document')));
        $this->assertSame(['create_shipping_document:50', 'create_shipping_document:50', 'create_shipping_document:20'], $creates);
        $firstDownload = array_search(true, array_map(fn ($c) => str_starts_with($c, 'download_shipping_document'), $this->calls), true);
        $lastCreate = max(array_keys(array_filter($this->calls, fn ($c) => str_starts_with($c, 'create_shipping_document'))));
        $this->assertGreaterThan($lastCreate, $firstDownload, 'all documents are requested before the first download');

        // "new" skips labels already printed.
        $this->assertSame('failed', AwbPrintJobs::start($this->seller, 'new')->status);
    }

    public function test_one_job_at_a_time_and_prune(): void
    {
        $ids = $this->orders(1);
        $busy = new AwbPrintJob(['scope' => 'selected', 'status' => 'running']);
        $busy->user_id = $this->seller->id;
        $busy->save();
        Sanctum::actingAs($this->seller);
        $this->postJson('/api/labels/shopee/print-jobs', ['scope' => 'selected', 'order_ids' => $ids])->assertStatus(422);

        $busy->forceFill(['created_at' => now()->subDays(4)])->save();
        $this->assertSame(1, AwbPrintJobs::prune());
    }
}
