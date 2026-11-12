<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\LandingPage;
use App\Models\Product;
use App\Support\CheckoutService;
use App\Support\LandingTemplates;
use App\Support\Plans;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LandingPageController extends Controller
{
    private const BLOCK_TYPES = ['announcement', 'headline', 'subheadline', 'text', 'image', 'slider', 'video', 'button',
        'whatsapp', 'features', 'testimonials', 'faq', 'countdown', 'pricing', 'checkout', 'divider', 'spacer', 'html'];

    public function index(Request $request)
    {
        $user = $request->user();
        $pages = $user->landingPages()
            ->with('product:id,name,images')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($request->query('search'), fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($request->query('label'), fn ($q, $l) => $q->where('label', $l))
            ->latest('updated_at')
            ->paginate(min(1000, max(1, $request->integer('per_page', 10))));

        $counts = $user->landingPages()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return [
            'pages' => $pages,
            'stats' => [
                'total' => (int) $counts->sum(),
                'draft' => (int) ($counts['draft'] ?? 0),
                'published' => (int) ($counts['published'] ?? 0),
                'archived' => (int) ($counts['archived'] ?? 0),
            ],
            'labels' => $user->landingPages()->whereNotNull('label')->distinct()->pluck('label'),
            'templates' => LandingTemplates::TEMPLATES,
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'template' => ['required', Rule::in(array_keys(LandingTemplates::TEMPLATES))],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
        ]);
        Plans::ensureQuota($request->user(), 'landing_pages');
        $product = isset($data['product_id']) ? Product::find($data['product_id']) : null;

        $page = $request->user()->landingPages()->create($data + [
            'slug' => $this->uniqueSlug(Str::slug($data['title'])),
            'status' => 'draft',
            'theme' => ['primary' => '#e05510', 'background' => '#ffffff', 'width' => 'narrow'],
            'seo' => ['title' => $data['title']],
            'settings' => ['floating_whatsapp' => false],
            'blocks' => LandingTemplates::blocks($data['template'], $product),
        ]);
        ActivityLog::record($request->user(), 'landing.create', "Membuat landing page {$page->title}");

        return response()->json($page, 201);
    }

    public function show(Request $request, LandingPage $landingPage)
    {
        $this->authorizeOwner($request, $landingPage);

        return $landingPage;
    }

    public function update(Request $request, LandingPage $landingPage)
    {
        $this->authorizeOwner($request, $landingPage);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:150',
            'slug' => ['sometimes', 'required', 'alpha_dash', 'max:120', Rule::unique('landing_pages')->ignore($landingPage->id)],
            'label' => 'nullable|string|max:40',
            'status' => 'sometimes|in:draft,published,archived',
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
            'theme' => 'sometimes|array',
            'theme.primary' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'theme.background' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/',
            'theme.width' => 'nullable|in:narrow,wide',
            'seo' => 'sometimes|array',
            'seo.title' => 'nullable|string|max:120',
            'seo.description' => 'nullable|string|max:300',
            'seo.image' => 'nullable|string|max:500',
            'settings' => 'sometimes|array',
            'settings.floating_whatsapp' => 'boolean',
            'settings.whatsapp' => 'nullable|string|max:30',
            'settings.facebook_pixel' => 'nullable|string|max:40',
            'settings.tiktok_pixel' => 'nullable|string|max:40',
            'blocks' => 'sometimes|array|max:80',
            'blocks.*.id' => 'required|string|max:20',
            'blocks.*.type' => ['required', Rule::in(self::BLOCK_TYPES)],
            'blocks.*.data' => 'present|array',
        ]);
        foreach ($data['blocks'] ?? [] as $b) {
            if ($b['type'] === 'checkout' && ! empty($b['data']['product_id'])
                && ! $request->user()->products()->whereKey($b['data']['product_id'])->exists()) {
                abort(422, 'Produk pada blok Formulir tidak ditemukan.');
            }
        }
        $landingPage->update($data);
        ActivityLog::record($request->user(), 'landing.update', "Mengubah landing page {$landingPage->title}");

        return $landingPage;
    }

    public function duplicate(Request $request, LandingPage $landingPage)
    {
        $this->authorizeOwner($request, $landingPage);
        Plans::ensureQuota($request->user(), 'landing_pages');
        $copy = $landingPage->replicate(['views']);
        $copy->title = $landingPage->title.' (Copy)';
        $copy->slug = $this->uniqueSlug($landingPage->slug.'-copy');
        $copy->status = 'draft';
        $copy->save();

        return response()->json($copy, 201);
    }

    public function destroy(Request $request, LandingPage $landingPage)
    {
        $this->authorizeOwner($request, $landingPage);
        $landingPage->delete();
        ActivityLog::record($request->user(), 'landing.delete', "Menghapus landing page {$landingPage->title}");

        return response()->noContent();
    }

    /** Public page: published only (or the owner's preview), with data for embedded checkout forms. */
    public function publicShow(Request $request, string $slug)
    {
        $page = LandingPage::with('user')->where('slug', $slug)->firstOrFail();
        abort_unless($page->status === 'published' || $request->boolean('preview'), 404);
        if ($page->status === 'published' && ! $request->boolean('preview')) {
            $page->increment('views');
        }

        $productIds = collect($page->blocks ?? [])->where('type', 'checkout')->pluck('data.product_id')->filter()->unique();
        $products = Product::with('user')->whereIn('id', $productIds)->where('user_id', $page->user_id)->where('status', 'active')->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => [
                'product' => $p->makeHidden(['cost_price', 'user_id', 'views', 'sku', 'user']),
                'payment_methods' => CheckoutService::paymentMethods($p),
                'fields' => \App\Support\ProductForm::fields($p),
                'bump_items' => \App\Support\ProductForm::bumpItems($p),
                'sold' => null,
                'store' => [
                    'store_name' => $p->user->store_name,
                    'cod_fee_percent' => $p->user->cod_fee_percent,
                    'origin' => $p->user->pickupAddress()?->region?->city,
                ],
            ]]);

        return [
            'page' => $page->only(['title', 'slug', 'theme', 'seo', 'settings', 'blocks']),
            'store' => ['store_name' => $page->user->store_name, 'whatsapp' => $page->settings['whatsapp'] ?? $page->user->whatsapp],
            'products' => $products,
        ];
    }

    private function uniqueSlug(string $base): string
    {
        $base = $base ?: Str::lower(Str::random(6));
        $slug = $base;
        $i = 1;
        while (DB::table('landing_pages')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    private function authorizeOwner(Request $request, LandingPage $page): void
    {
        abort_unless($page->user_id === $request->user()->id, 404);
    }
}
