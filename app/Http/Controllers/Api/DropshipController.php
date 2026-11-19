<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\DropshipAdoption;
use App\Models\DropshipperProfile;
use App\Models\DropshipProduct;
use App\Models\LandingPage;
use App\Models\Product;
use App\Support\SidebarConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DropshipController extends Controller
{
    /* ── Admin Console: Dropship Catalog CRUD ─────────────────────────────── */

    public function adminIndex(Request $request)
    {
        $products = DropshipProduct::query()
            ->withCount('adoptions as adopters_count')
            ->when($request->query('product_type'), fn ($q, $t) => $q->where('product_type', $t))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('search'), function ($q, $s) {
                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('sku', 'like', "%{$s}%")
                    ->orWhere('category', 'like', "%{$s}%")
                    ->orWhere('warehouse_city', 'like', "%{$s}%")
                    ->orWhere('digital_format', 'like', "%{$s}%"));
            })
            ->orderByDesc('featured')
            ->latest('id')
            ->get();

        $all = DropshipProduct::query()->withCount('adoptions as adopters_count')->get();
        $stats = [
            'total' => $all->count(),
            'active' => $all->where('status', 'active')->count(),
            'physical' => $all->where('product_type', 'physical')->count(),
            'digital' => $all->where('product_type', 'digital')->count(),
            'assets' => $all->sum(fn ($p) => count($p->videos ?? []) + count($p->slides ?? [])),
            'lps' => $all->sum(fn ($p) => count($p->lp_templates ?? [])),
            'adopters' => (int) $all->sum('adopters_count'),
        ];

        return [
            'products' => $products->map(fn ($p) => $this->serializeProduct($p))->values(),
            'stats' => $stats,
        ];
    }

    public function adminShow(DropshipProduct $dropshipProduct)
    {
        $dropshipProduct->loadCount('adoptions as adopters_count');

        return $this->serializeProduct($dropshipProduct);
    }

    public function adminStore(Request $request)
    {
        $data = $this->validateProduct($request);
        $data['sku'] = $this->uniqueSku($data['sku'] ?: 'PSN-DS-'.strtoupper(Str::random(4)));
        $product = DropshipProduct::create($data);
        $product->loadCount('adoptions as adopters_count');

        ActivityLog::record($request->user(), 'admin.dropship.create', "Menambahkan produk dropship {$product->name}");

        return response()->json($this->serializeProduct($product), 201);
    }

    public function adminUpdate(Request $request, DropshipProduct $dropshipProduct)
    {
        $data = $this->validateProduct($request, $dropshipProduct->id);
        $dropshipProduct->update($data);
        $dropshipProduct->loadCount('adoptions as adopters_count');

        ActivityLog::record($request->user(), 'admin.dropship.update', "Memperbarui produk dropship {$dropshipProduct->name}");

        return $this->serializeProduct($dropshipProduct);
    }

    public function adminDuplicate(Request $request, DropshipProduct $dropshipProduct)
    {
        $copy = $dropshipProduct->replicate(['sold_30d']);
        $copy->sku = $this->uniqueSku($dropshipProduct->sku.'-COPY');
        $copy->name = $dropshipProduct->name.' (Salinan)';
        $copy->status = 'draft';
        $copy->sold_30d = 0;
        $copy->save();
        $copy->loadCount('adoptions as adopters_count');

        return response()->json($this->serializeProduct($copy), 201);
    }

    public function adminDestroy(Request $request, DropshipProduct $dropshipProduct)
    {
        $name = $dropshipProduct->name;
        $dropshipProduct->delete();
        ActivityLog::record($request->user(), 'admin.dropship.delete', "Menghapus produk dropship {$name}");

        return response()->noContent();
    }

    /* ── Seller App: Catalog, Dropshipper Profile & Adoption ──────────────── */

    public function index(Request $request)
    {
        $user = $request->user();
        $profile = DropshipperProfile::where('user_id', $user->id)->first();
        $products = DropshipProduct::query()
            ->where('status', 'active')
            ->withCount('adoptions as adopters_count')
            ->orderByDesc('featured')
            ->orderByDesc('sold_30d')
            ->latest('id')
            ->get();

        $adoptions = DropshipAdoption::query()
            ->where('user_id', $user->id)
            ->with(['landingPage', 'product'])
            ->latest('id')
            ->get();

        return [
            'enabled' => SidebarConfig::isEnabled('dropship'),
            'profile' => $this->serializeProfile($profile, $user),
            'products' => $products->map(fn ($p) => $this->serializeProduct($p))->values(),
            'adoptions' => $adoptions->map(fn ($a) => $this->serializeAdoption($a))->values(),
        ];
    }

    public function show(Request $request, DropshipProduct $dropshipProduct)
    {
        abort_if($dropshipProduct->status !== 'active' && ! $request->user()->is_admin, 404);
        $user = $request->user();
        $dropshipProduct->loadCount('adoptions as adopters_count');
        $profile = DropshipperProfile::where('user_id', $user->id)->first();
        $adoption = DropshipAdoption::query()
            ->where('user_id', $user->id)
            ->where('dropship_product_id', $dropshipProduct->id)
            ->with(['landingPage', 'product'])
            ->first();

        return [
            'enabled' => SidebarConfig::isEnabled('dropship'),
            'product' => $this->serializeProduct($dropshipProduct),
            'profile' => $this->serializeProfile($profile, $user),
            'adoption' => $adoption ? $this->serializeAdoption($adoption) : null,
        ];
    }

    public function saveProfile(Request $request)
    {
        $data = $request->validate([
            'brand_name' => 'required|string|max:120',
            'whatsapp' => 'required|string|max:30',
            'bank_name' => 'nullable|string|max:40',
            'bank_account' => 'nullable|string|max:60',
            'bank_holder' => 'nullable|string|max:100',
            'channels' => 'nullable|array|max:10',
            'channels.*' => 'string|max:40',
        ]);

        $user = $request->user();
        $existing = DropshipperProfile::where('user_id', $user->id)->first();
        $profile = DropshipperProfile::updateOrCreate(
            ['user_id' => $user->id],
            [
                'brand_name' => $data['brand_name'],
                'whatsapp' => $data['whatsapp'],
                'bank_name' => $data['bank_name'] ?: 'BCA',
                'bank_account' => $data['bank_account'] ?? '',
                'bank_holder' => $data['bank_holder'] ?? '',
                'channels' => $data['channels'] ?? ['TikTok', 'Instagram'],
                'joined_at' => $existing?->joined_at ?? now(),
            ],
        );

        ActivityLog::record($user, 'dropship.profile', "Memperbarui profil dropshipper {$profile->brand_name}");

        return ['profile' => $this->serializeProfile($profile, $user)];
    }

    /**
     * Adopt (or update) a catalog dropship product into the seller's store:
     * syncs a real Product + real LandingPage in the database so `/p/{slug}` & checkout work live.
     */
    public function adopt(Request $request)
    {
        $user = $request->user();
        $profile = DropshipperProfile::where('user_id', $user->id)->first();
        abort_unless($profile, 422, 'Silakan daftar sebagai Dropshipper Pesanin terlebih dahulu.');

        $data = $request->validate([
            'dropship_product_id' => 'required|integer|exists:dropship_products,id',
            'custom_name' => 'required|string|max:180',
            'selling_price' => 'required|integer|min:1000',
            'compare_price' => 'nullable|integer|min:0',
            'slug' => 'required|string|max:120',
            'selected_lp_id' => 'nullable|string|max:80',
            'payment_methods' => 'required|array|min:1',
            'payment_methods.*' => Rule::in(['cod', 'bank_transfer', 'epayment']),
            'lp_page' => 'nullable|array',
            'lp_page.title' => 'nullable|string|max:150',
            'lp_page.label' => 'nullable|string|max:40',
            'lp_page.status' => 'nullable|in:draft,published,archived',
            'lp_page.template' => 'nullable|string|max:60',
            'lp_page.theme' => 'nullable|array',
            'lp_page.seo' => 'nullable|array',
            'lp_page.settings' => 'nullable|array',
            'lp_page.blocks' => 'nullable|array|max:80',
        ]);

        $dp = DropshipProduct::where('status', 'active')->findOrFail($data['dropship_product_id']);
        abort_if($data['selling_price'] < $dp->min_price, 422, 'Harga jual minimal untuk produk ini adalah Rp'.number_format($dp->min_price, 0, ',', '.').'.');

        $paymentMethods = array_values(array_unique($data['payment_methods']));
        $comparePrice = (int) ($data['compare_price'] ?? 0);
        $sellingPrice = (int) $data['selling_price'];
        $lpInput = $data['lp_page'] ?? [];

        $adoption = DB::transaction(function () use ($user, $profile, $dp, $data, $paymentMethods, $comparePrice, $sellingPrice, $lpInput) {
            $existing = DropshipAdoption::where('user_id', $user->id)
                ->where('dropship_product_id', $dp->id)
                ->first();

            // 1. Sync real Product in `products` table (so live checkout & orders work)
            $product = $existing?->product_id ? Product::where('user_id', $user->id)->find($existing->product_id) : null;
            $productPayload = [
                'name' => $data['custom_name'],
                'type' => $dp->product_type === 'digital' ? 'digital' : 'physical',
                'category' => $dp->category,
                'status' => 'active',
                'price' => $comparePrice > $sellingPrice ? $comparePrice : $sellingPrice,
                'sale_price' => $comparePrice > $sellingPrice ? $sellingPrice : null,
                'cost_price' => $dp->wholesale_price,
                'sku' => $dp->sku,
                'track_stock' => false,
                'stock' => $dp->stock,
                'allow_oversell' => true,
                'min_purchase' => 1,
                'weight' => $dp->product_type === 'digital' ? 0 : max(1, (int) $dp->weight_gram),
                'images' => $dp->images ?? [],
                'description' => $dp->description,
                'variants' => collect($dp->variants ?? [])->map(fn ($v) => [
                    'name' => $v['name'] ?? 'Standar',
                    'price' => $sellingPrice,
                    'stock' => (int) ($v['stock'] ?? $dp->stock),
                ])->values()->all(),
                'payment_methods' => $paymentMethods,
                'digital_content' => $dp->product_type === 'digital' ? [
                    'url' => $dp->digital_access_url,
                    'instructions' => $dp->digital_format,
                ] : null,
                'button_text' => $dp->product_type === 'digital' ? 'Beli & Dapatkan Akses' : 'Pesan Sekarang',
                'form_settings' => [
                    'theme' => 'new',
                    'payment' => [
                        'epayment' => ['channels' => ['qris', 'bca_va', 'bri_va', 'mandiri_va', 'gopay', 'ovo']],
                    ],
                ],
                'success_settings' => ['action' => 'page'],
            ];

            if ($product) {
                $product->update($productPayload);
            } else {
                $productPayload['slug'] = $this->uniqueProductSlug(Str::slug($data['custom_name']) ?: 'dropship-produk');
                $product = $user->products()->create($productPayload);
            }

            // 2. Prepare LandingPage blocks (bind every `checkout` block to the real `$product->id`)
            $blocks = is_array($lpInput['blocks'] ?? null) ? $lpInput['blocks'] : [];
            if (empty($blocks)) {
                $tpl = collect($dp->lp_templates ?? [])->firstWhere('id', $data['selected_lp_id'])
                    ?? ($dp->lp_templates[0] ?? null);
                $blocks = is_array($tpl['blocks'] ?? null) ? $tpl['blocks'] : [];
            }
            $blocks = array_map(function ($b) use ($product, $sellingPrice, $comparePrice) {
                if (! is_array($b)) {
                    return $b;
                }
                if (($b['type'] ?? null) === 'checkout') {
                    $b['data'] = array_merge($b['data'] ?? [], ['product_id' => $product->id]);
                } elseif (($b['type'] ?? null) === 'pricing') {
                    $b['data'] = array_merge($b['data'] ?? [], [
                        'price' => $sellingPrice,
                        'normal' => $comparePrice > $sellingPrice ? $comparePrice : 0,
                    ]);
                }

                return $b;
            }, $blocks);

            // 3. Sync real LandingPage in `landing_pages` table
            $landingPage = $existing?->landing_page_id ? LandingPage::where('user_id', $user->id)->find($existing->landing_page_id) : null;
            $baseSlug = Str::slug($data['slug']) ?: Str::slug($data['custom_name']) ?: 'dropship-lp';
            $uniqueSlug = $this->uniqueLandingSlug($baseSlug, $landingPage?->id);

            $lpPayload = [
                'product_id' => $product->id,
                'title' => $lpInput['title'] ?? $data['custom_name'],
                'slug' => $uniqueSlug,
                'label' => $lpInput['label'] ?? 'Dropship',
                'status' => in_array($lpInput['status'] ?? 'published', ['draft', 'published', 'archived'], true) ? ($lpInput['status'] ?? 'published') : 'published',
                'template' => $lpInput['template'] ?? 'product_fisik',
                'theme' => array_merge(['primary' => '#e05510', 'background' => '#ffffff', 'width' => 'narrow'], $lpInput['theme'] ?? []),
                'seo' => array_merge(['title' => $data['custom_name'], 'description' => Str::limit((string) $dp->description, 280)], $lpInput['seo'] ?? []),
                'settings' => array_merge(['floating_whatsapp' => true, 'whatsapp' => $profile->whatsapp], $lpInput['settings'] ?? []),
                'blocks' => $blocks,
            ];

            if ($landingPage) {
                $landingPage->update($lpPayload);
            } else {
                $landingPage = $user->landingPages()->create($lpPayload);
            }

            // 4. Upsert DropshipAdoption
            $adoption = DropshipAdoption::updateOrCreate(
                ['user_id' => $user->id, 'dropship_product_id' => $dp->id],
                [
                    'product_id' => $product->id,
                    'landing_page_id' => $landingPage->id,
                    'custom_name' => $data['custom_name'],
                    'selling_price' => $sellingPrice,
                    'compare_price' => $comparePrice,
                    'slug' => $landingPage->slug,
                    'selected_lp_id' => $data['selected_lp_id'] ?? ($dp->lp_templates[0]['id'] ?? null),
                    'payment_methods' => $paymentMethods,
                    'lp_page' => $landingPage->fresh()->toArray(),
                    'status' => 'active',
                ],
            );

            ActivityLog::record($user, 'dropship.adopt', "Menyimpan produk dropship {$adoption->custom_name}");

            return $adoption->load(['landingPage', 'product']);
        });

        return response()->json(['adoption' => $this->serializeAdoption($adoption)], 201);
    }

    public function destroyAdoption(Request $request, DropshipAdoption $adoption)
    {
        abort_unless($adoption->user_id === $request->user()->id, 403);
        if ($adoption->landingPage) {
            $adoption->landingPage->update(['status' => 'archived']);
        }
        $adoption->delete();

        return response()->noContent();
    }

    /* ── Validation & Serializers ─────────────────────────────────────────── */

    private function validateProduct(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:80', Rule::unique('dropship_products', 'sku')->ignore($ignoreId)],
            'product_type' => 'required|in:physical,digital',
            'name' => 'required|string|max:200',
            'category' => 'required|string|max:100',
            'status' => 'required|in:active,draft',
            'featured' => 'sometimes|boolean',
            'description' => 'nullable|string|max:5000',
            'images' => 'nullable|array|max:12',
            'images.*' => 'string|max:500',
            'weight_gram' => 'nullable|integer|min:0|max:100000',
            'stock' => 'nullable|integer|min:0|max:1000000',
            'warehouse_city' => 'nullable|string|max:120',
            'digital_format' => 'nullable|string|max:180',
            'digital_access_url' => 'nullable|string|max:500',
            'digital_license' => 'nullable|string|max:160',
            'wholesale_price' => 'required|integer|min:0',
            'min_price' => 'required|integer|min:0',
            'suggested_price' => 'required|integer|min:0',
            'compare_price' => 'nullable|integer|min:0',
            'payment_methods' => 'nullable|array',
            'payment_methods.*' => Rule::in(['cod', 'bank_transfer', 'epayment']),
            'variants' => 'nullable|array|max:30',
            'videos' => 'nullable|array|max:20',
            'slides' => 'nullable|array|max:20',
            'lp_templates' => 'nullable|array|max:15',
            'sold_30d' => 'nullable|integer|min:0',
        ]);

        $data['images'] = array_values(array_filter($data['images'] ?? []));
        $data['payment_methods'] = ! empty($data['payment_methods'])
            ? array_values(array_unique($data['payment_methods']))
            : ['cod', 'bank_transfer', 'epayment'];
        $data['variants'] = $data['variants'] ?? [];
        $data['videos'] = $data['videos'] ?? [];
        $data['slides'] = $data['slides'] ?? [];
        $data['lp_templates'] = $data['lp_templates'] ?? [];
        $data['weight_gram'] = (int) ($data['weight_gram'] ?? 0);
        $data['stock'] = (int) ($data['stock'] ?? 0);
        $data['compare_price'] = (int) ($data['compare_price'] ?? 0);
        $data['featured'] = (bool) ($data['featured'] ?? false);

        return $data;
    }

    private function serializeProduct(DropshipProduct $p): array
    {
        return [
            'id' => $p->id,
            'sku' => $p->sku,
            'product_type' => $p->product_type,
            'name' => $p->name,
            'category' => $p->category,
            'status' => $p->status,
            'featured' => (bool) $p->featured,
            'description' => $p->description ?? '',
            'images' => $p->images ?? [],
            'weight_gram' => (int) $p->weight_gram,
            'stock' => (int) $p->stock,
            'warehouse_city' => $p->warehouse_city ?? '',
            'digital_format' => $p->digital_format ?? '',
            'digital_access_url' => $p->digital_access_url ?? '',
            'digital_license' => $p->digital_license ?? '',
            'wholesale_price' => (int) $p->wholesale_price,
            'min_price' => (int) $p->min_price,
            'suggested_price' => (int) $p->suggested_price,
            'compare_price' => (int) $p->compare_price,
            'payment_methods' => $p->payment_methods ?: ['cod', 'bank_transfer', 'epayment'],
            'variants' => $p->variants ?? [],
            'videos' => $p->videos ?? [],
            'slides' => $p->slides ?? [],
            'lp_templates' => $p->lp_templates ?? [],
            'adopters_count' => (int) ($p->adopters_count ?? 0),
            'sold_30d' => (int) $p->sold_30d,
            'created_at' => optional($p->created_at)->toDateString(),
        ];
    }

    private function serializeProfile(?DropshipperProfile $profile, $user): array
    {
        if (! $profile) {
            return [
                'registered' => false,
                'brand_name' => $user->store_name ?? '',
                'whatsapp' => $user->whatsapp ?? '',
                'bank_name' => $user->bank_accounts[0]['bank'] ?? 'BCA',
                'bank_account' => $user->bank_accounts[0]['number'] ?? '',
                'bank_holder' => $user->bank_accounts[0]['holder'] ?? ($user->name ?? ''),
                'channels' => ['TikTok', 'Instagram'],
                'joined_at' => null,
            ];
        }

        return [
            'registered' => true,
            'brand_name' => $profile->brand_name,
            'whatsapp' => $profile->whatsapp,
            'bank_name' => $profile->bank_name,
            'bank_account' => $profile->bank_account ?? '',
            'bank_holder' => $profile->bank_holder ?? '',
            'channels' => $profile->channels ?? [],
            'joined_at' => optional($profile->joined_at)->toDateString(),
        ];
    }

    private function serializeAdoption(DropshipAdoption $a): array
    {
        $lp = $a->landingPage;

        return [
            'id' => $a->id,
            'dropship_product_id' => $a->dropship_product_id,
            'product_id' => $a->product_id,
            'landing_page_id' => $a->landing_page_id,
            'custom_name' => $a->custom_name,
            'selling_price' => (int) $a->selling_price,
            'compare_price' => (int) $a->compare_price,
            'slug' => $lp?->slug ?? $a->slug,
            'selected_lp_id' => $a->selected_lp_id ?? '',
            'payment_methods' => $a->payment_methods ?: ($a->product?->payment_methods ?: ['cod', 'bank_transfer', 'epayment']),
            'lp_page' => $lp ? $lp->toArray() : ($a->lp_page ?? null),
            'status' => $a->status,
            'created_at' => optional($a->created_at)->toDateString(),
        ];
    }

    private function uniqueSku(string $base): string
    {
        $sku = $base;
        $i = 1;
        while (DropshipProduct::where('sku', $sku)->exists()) {
            $sku = $base.'-'.++$i;
        }

        return $sku;
    }

    private function uniqueProductSlug(string $base): string
    {
        $slug = $base;
        $i = 1;
        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    private function uniqueLandingSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = $base;
        $i = 1;
        while (LandingPage::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }
}
