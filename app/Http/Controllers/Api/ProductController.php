<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Support\Plans;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public const CATEGORIES = [
        'Buku', 'Dapur', 'Elektronik', 'Fashion Anak', 'Fashion Muslim', 'Fashion Pria', 'Fashion Wanita',
        'Film & Musik', 'Gaming', 'Handphone & Tablet', 'Ibu & Bayi', 'Kamera', 'Kecantikan', 'Kesehatan',
        'Komputer & Aksesoris', 'Laptop & Aksesoris', 'Mainan & Hobi', 'Makanan & Minuman',
        'Office & Stationery', 'Olahraga', 'Otomotif', 'Perawatan Tubuh', 'Produk Lainnya',
        'Rumah Tangga', 'Software', 'Souvenir & Kado',
    ];

    public function index(Request $request)
    {
        $user = $request->user();
        $type = $request->query('type', 'physical');

        $products = $user->products()
            ->where('type', $type)
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->withCount([
                'orders as orders_count' => fn ($q) => $q->where('is_abandoned', false),
                'orders as paid_count' => fn ($q) => $q->where('is_abandoned', false)->where('payment_status', 'paid'),
                'orders as bump_count' => fn ($q) => $q->where('is_abandoned', false)->where('bump_selected', true),
            ])
            ->withSum(['orders as sold_qty' => fn ($q) => $q->where('is_abandoned', false)->where('payment_status', 'paid')], 'quantity')
            ->withSum(['orders as net_revenue' => fn ($q) => $q->where('is_abandoned', false)->where('payment_status', 'paid')], 'total')
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 10))));

        $paidOrders = $user->orders()->active()->where('payment_status', 'paid');

        return [
            'products' => $products,
            'summary' => [
                'total_products' => $user->products()->count(),
                'total_sold' => (int) (clone $paidOrders)->sum('quantity'),
                'total_paid_orders' => (clone $paidOrders)->count(),
            ],
        ];
    }

    public function categories()
    {
        return self::CATEGORIES;
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Plans::ensureQuota($request->user(), 'products');
        $product = $request->user()->products()->create($data);
        ActivityLog::record($request->user(), 'product.create', "Menambah produk {$product->name}");

        return response()->json($product, 201);
    }

    public function show(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);

        return $product;
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);
        $product->update($this->validated($request, $product));
        ActivityLog::record($request->user(), 'product.update', "Mengubah produk {$product->name}");

        return $product;
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);
        $product->delete();
        ActivityLog::record($request->user(), 'product.delete', "Menghapus produk {$product->name}");

        return response()->noContent();
    }

    public function duplicate(Request $request, Product $product)
    {
        $this->authorizeOwner($request, $product);
        Plans::ensureQuota($request->user(), 'products');
        $copy = $product->replicate(['views']);
        $copy->name = $product->name.' (Copy)';
        $copy->slug = $this->uniqueSlug($product->slug.'-copy');
        $copy->status = 'draft';
        $copy->save();
        ActivityLog::record($request->user(), 'product.duplicate', "Menggandakan produk {$product->name}");

        return response()->json($copy, 201);
    }

    public function upload(Request $request)
    {
        $request->validate(['files' => 'required|array|max:10', 'files.*' => 'image|max:4096']);
        $urls = [];
        foreach ($request->file('files') as $file) {
            $path = $file->store('products', 'public');
            $urls[] = asset('storage/'.$path);
        }

        return ['urls' => $urls];
    }

    /** Digital product file (ebook, zip, video…): kept private, buyers download it through their access link. */
    public function uploadDigital(Request $request)
    {
        $request->validate(['file' => 'required|file|max:51200|mimes:pdf,zip,rar,7z,epub,mp4,mp3,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,psd,ai,txt,csv']);
        $file = $request->file('file');

        return ['file' => $file->store('digital/'.$request->user()->id, 'local'), 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200)];
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'slug' => ['nullable', 'alpha_dash', 'max:120', Rule::unique('products')->ignore($product?->id)],
            'site_title' => 'nullable|string|max:64',
            'type' => 'in:physical,digital',
            'category' => ['nullable', Rule::in(self::CATEGORIES)],
            'status' => 'in:active,draft',
            'is_preorder' => 'boolean',
            'preorder_days' => 'nullable|integer|min:1|max:365',
            'price' => 'required|integer|min:0',
            'sale_price' => 'nullable|integer|min:0|lt:price',
            'cost_price' => 'nullable|integer|min:0',
            'wholesale_prices' => 'nullable|array',
            'wholesale_prices.*.min_qty' => 'required|integer|min:2',
            'wholesale_prices.*.price' => 'required|integer|min:0',
            'sku' => 'nullable|string|max:64',
            'track_stock' => 'boolean',
            'stock' => 'integer',
            'allow_oversell' => 'boolean',
            'min_purchase' => 'integer|min:1',
            'weight' => 'integer|min:1',
            'images' => 'nullable|array|max:10',
            'images.*' => 'string',
            'autoslide' => 'boolean',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'features.*' => 'string|max:200',
            'variants' => 'nullable|array',
            'variants.*.name' => 'required|string|max:100',
            'variants.*.price' => 'nullable|integer|min:0',
            'variants.*.stock' => 'nullable|integer',
            'variants.*.image' => 'nullable|string|max:500',
            'variant_options' => 'nullable|array|max:2',
            'variant_options.*.type' => 'required|string|max:40',
            'variant_options.*.values' => 'required|array|min:1|max:30',
            'variant_options.*.values.*' => 'required|string|max:60',
            'bump' => 'nullable|array',
            'bump.enabled' => 'boolean',
            'bump.items' => 'nullable|array|max:3',
            'bump.items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
            'bump.items.*.price' => 'nullable|integer|min:0',
            'bump.items.*.description' => 'nullable|string|max:300',
            'responsible_user_id' => 'nullable|in:'.$request->user()->id,
            'payment_methods' => 'required|array|min:1',
            'payment_methods.*' => 'in:bank_transfer,cod,epayment',
            'whatsapp_validator' => 'boolean',
            'captcha' => 'boolean',
            'button_text' => 'nullable|string|max:50',
            'form_settings' => 'nullable|array',
            'form_settings.form_type' => 'nullable|in:single,multiple',
            'form_settings.theme' => 'nullable|in:classic,new',
            'form_settings.layout' => 'nullable|in:sidebar_right,sidebar_left,single',
            'form_settings.background' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'form_settings.page_name' => 'nullable|string|max:120',
            'form_settings.header_image' => 'nullable|string|max:500',
            'form_settings.countdown' => 'nullable|array',
            'form_settings.countdown.until' => 'nullable|date',
            'form_settings.title' => 'nullable|array',
            'form_settings.tagline' => 'nullable|array',
            'form_settings.components' => 'nullable|array',
            'form_settings.fields' => 'nullable|array',
            'form_settings.fields.items' => 'nullable|array',
            'form_settings.fields.items.*.key' => 'required|in:'.implode(',', \App\Support\ProductForm::FIELD_KEYS),
            'form_settings.exclude_locations' => 'nullable|array',
            'form_settings.testimonials' => 'nullable|array',
            'form_settings.tracking' => 'nullable|array',
            'form_settings.tracking.conversion' => 'nullable|in:every,once',
            'form_settings.tracking.pixels' => 'nullable|array|max:10',
            'form_settings.tracking.pixels.*.platform' => 'required|in:facebook,tiktok,google_ads,gtm,snack',
            'form_settings.tracking.pixels.*.id' => 'required|string|max:60|regex:/^[A-Za-z0-9_-]+$/',
            'form_settings.tracking.pixels.*.event' => 'nullable|string|max:40',
            'success_settings' => 'nullable|array',
            // Digital product: what the buyer gets once paid (link, uploaded file, instructions).
            'digital_content' => 'nullable|array',
            'digital_content.url' => 'nullable|url|max:1000',
            'digital_content.file' => 'nullable|string|max:1000',
            'digital_content.file_name' => 'nullable|string|max:200',
            'digital_content.instructions' => 'nullable|string|max:3000',
            'success_settings.action' => 'nullable|in:page,whatsapp,whatsapp_random,url',
            'success_settings.components' => 'nullable|array',
            'success_settings.background' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'success_settings.title' => 'nullable|array',
            'success_settings.tagline' => 'nullable|array',
            'success_settings.pixels' => 'nullable|array|max:10',
            'success_settings.pixels.*.id' => 'nullable|string|max:60|regex:/^[A-Za-z0-9_-]*$/',
            'form_settings.payment' => 'nullable|array',
            'form_settings.payment.cod.fee_percent' => 'nullable|numeric|min:0|max:20',
            'form_settings.payment.cod.min_fee' => 'nullable|integer|min:0',
            'form_settings.payment.cod.max_fee' => 'nullable|integer|min:0',
            'form_settings.shipping' => 'nullable|array',
            'form_settings.shipping.extra_fee' => 'nullable|integer|min:-1000000|max:1000000',
            'form_settings.shipping.couriers' => 'nullable|array',
            'success_settings.whatsapp' => 'nullable|string|max:30',
            'success_settings.redirect_url' => 'nullable|url|required_if:success_settings.action,url',
            'success_settings.messages' => 'nullable|array',
            'success_settings.messages.*' => 'nullable|string|max:1000',
            'pixels' => 'nullable|array',
            'pixels.facebook' => 'nullable|string|max:40',
            'pixels.tiktok' => 'nullable|string|max:40',
            'pixels.google_ads' => 'nullable|string|max:40',
        ], [
            'sale_price.lt' => 'Harga diskon harus lebih kecil dari harga normal.',
        ]);
        // A digital file must be one this seller uploaded.
        abort_if(! empty($data['digital_content']['file']) && ! str_starts_with($data['digital_content']['file'], 'digital/'.$request->user()->id.'/'), 422, 'File produk digital tidak valid.');
        $data['slug'] = $data['slug'] ?? $product?->slug ?? $this->uniqueSlug(Str::slug($data['name']));
        $data['button_text'] ??= 'Beli Sekarang';
        if (($data['type'] ?? $product?->type) === 'digital') {
            $data['payment_methods'] = array_values(array_diff($data['payment_methods'], ['cod'])) ?: ['bank_transfer'];
        }

        return $data;
    }

    private function uniqueSlug(string $base): string
    {
        $base = $base ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 1;
        while (DB::table('products')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    private function authorizeOwner(Request $request, Product $product): void
    {
        abort_unless($product->user_id === $request->user()->id, 404);
    }
}
