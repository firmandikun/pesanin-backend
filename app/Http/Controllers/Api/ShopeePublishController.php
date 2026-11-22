<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceShop;
use App\Models\Product;
use App\Support\Shopee\ShopeeClient;
use App\Support\Shopee\ShopeeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upload produk ke Shopee, one shop per request so the UI can show progress across many shops.
 * Flow: categories → category detail (attributes, brands, limits) → upload images once (image_ids work
 * in every shop) → publish to each selected shop (add_item + init_tier_variation) → listing rows linked
 * to the Pesanin product so stock/HPP/profit work right away.
 */
class ShopeePublishController extends Controller
{
    /** Leaf categories with their full path, for a searchable picker. */
    public function categories(Request $request)
    {
        return $this->leaves($this->anyShop($request))->sortBy('path')->values();
    }

    /** "Rekomendasi Kategori": Shopee's suggestions for the product name (+ cover image), as picker rows. */
    public function recommend(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|min:3|max:226', 'image_id' => 'nullable|string|max:100']);
        $shop = $this->anyShop($request);
        try {
            $ids = (new ShopeeClient)->categoryRecommend($shop, $d['name'], $d['image_id'] ?? null);
        } catch (ShopeeException) {
            return [];
        }
        $leaves = $this->leaves($shop)->keyBy('id');

        return collect($ids)->map(fn ($id) => $leaves[$id] ?? null)->filter()->take(5)->values();
    }

    /** Leaf categories with their full path ("Kecantikan › Perawatan Wajah › Serum"). */
    private function leaves(MarketplaceShop $shop)
    {
        $all = collect((new ShopeeClient)->categories($shop))->keyBy('id');
        $path = function ($c) use ($all) {
            $names = [];
            for ($i = 0; $c && $i < 8; $i++, $c = $all[$c['parent']] ?? null) {
                array_unshift($names, $c['name']);
            }

            return implode(' › ', $names);
        };

        return $all->where('leaf', true)->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'path' => $path($c)])->keyBy('id');
    }

    public function category(Request $request, int $category)
    {
        $api = new ShopeeClient;
        $shop = $this->anyShop($request);
        try {
            $limit = $api->itemLimit($shop, $category);
        } catch (Throwable) {
            $limit = [];
        }

        return [
            'attributes' => $api->attributes($shop, $category),
            'brands' => $api->brands($shop, $category),
            'limits' => [
                'images_min' => (int) ($limit['item_image_count_limit']['min_limit'] ?? 1),
                'images_max' => (int) ($limit['item_image_count_limit']['max_limit'] ?? 9),
                'name_min' => (int) ($limit['item_name_length_limit']['min_limit'] ?? 1),
                'name_max' => (int) ($limit['item_name_length_limit']['max_limit'] ?? 120),
                'description_max' => (int) ($limit['item_description_length_limit']['max_limit'] ?? 3000),
                'price_min' => (int) ($limit['price_limit']['min_limit'] ?? 100),
                // Pre-order days range, and the days to ship of a normal (ready stock) item.
                'dts_min' => (int) ($limit['dts_limit']['days_to_ship_limit']['min_limit'] ?? 5),
                'dts_max' => (int) ($limit['dts_limit']['days_to_ship_limit']['max_limit'] ?? 15),
                'dts_normal' => (int) ($limit['dts_limit']['non_pre_order_days_to_ship'] ?? 2),
                'weight_mandatory' => (bool) ($limit['weight_limit']['weight_mandatory'] ?? true),
                'dimension_mandatory' => (bool) ($limit['dimension_limit']['dimension_mandatory'] ?? false),
                // Mandatory / Flexible / Optional — "00" means "Produk tanpa GTIN".
                'gtin_rule' => $limit['gtin_limit']['gtin_validation_rule'] ?? 'Optional',
            ],
        ];
    }

    /** Shops the seller can publish to, with the couriers each will use. */
    public function shops(Request $request)
    {
        $api = new ShopeeClient;

        return $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)->get()->map(function ($s) use ($api) {
            try {
                $channels = $api->enabledChannels($s);
                $error = $channels ? null : 'Belum ada jasa kirim aktif di toko ini.';
            } catch (Throwable $e) {
                [$channels, $error] = [[], $e->getMessage()];
            }

            return ['id' => $s->id, 'shop_id' => $s->shop_id, 'name' => $s->shop_name, 'channels' => $channels, 'error' => $error];
        });
    }

    /** Images: uploaded files and/or existing product image URLs → Shopee image_ids. */
    public function images(Request $request)
    {
        $request->validate(['files' => 'nullable|array|max:9', 'files.*' => 'image|mimes:jpg,jpeg,png|max:10240', 'urls' => 'nullable|array|max:9', 'urls.*' => 'url']);
        $api = new ShopeeClient;
        $out = [];
        try {
            foreach ($request->file('files', []) as $f) {
                $out[] = ['id' => $api->uploadImage($f->get(), $f->getClientOriginalName()), 'name' => $f->getClientOriginalName()];
            }
            foreach ($request->input('urls', []) as $url) {
                $res = Http::timeout(20)->get($url);
                abort_unless($res->successful(), 422, "Gambar tidak bisa diambil: {$url}");
                $out[] = ['id' => $api->uploadImage($res->body(), basename(parse_url($url, PHP_URL_PATH)) ?: 'image.jpg'), 'name' => basename($url)];
            }
        } catch (ShopeeException $e) {
            abort(422, 'Upload gambar ke Shopee gagal: '.$e->getMessage());
        }

        return collect($out)->map(fn ($i) => $i + ['url' => $this->imageUrl($i['id'])]);
    }

    /** Video: init → upload every part → complete/transcode → video_upload_id. */
    public function video(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:30720',
        ], [
            'file.max' => 'Ukuran video maksimal 30 MB.',
        ]);
        $file = $request->file('file');
        abort_unless(in_array(strtolower($file->getClientOriginalExtension()), ['mp4', 'mov', 'm4v'], true), 422, 'Video harus berformat MP4, MOV, atau M4V.');
        $shop = $request->user()->marketplaceShops()
            ->where('platform', 'shopee')
            ->where('status', 'active')
            ->where('is_demo', false)
            ->when($request->integer('shop_id'), fn ($q, $id) => $q->whereKey($id))
            ->first();
        abort_unless($shop, 422, 'Hubungkan toko Shopee aktif terlebih dahulu untuk upload video.');
        try {
            return [
                'video_upload_id' => (new ShopeeClient)->uploadVideo($file->get(), $file->getClientOriginalName(), $shop),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ];
        } catch (ShopeeException $e) {
            Log::warning('Shopee product video upload failed', [
                'user_id' => $request->user()?->id,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
                'error' => $e->getMessage(),
            ]);
            abort(422, 'Upload video ke Shopee gagal: '.$e->getMessage());
        }
    }

    /** Publish to ONE shop; the UI loops over the selected shops. */
    public function publish(Request $request, MarketplaceShop $shop)
    {
        abort_unless($shop->user_id === $request->user()->id && $shop->status === 'active', 404);
        // Treat 0/empty package dimensions as unset when none of the three is > 0.
        if ((int) $request->input('length') <= 0 && (int) $request->input('width') <= 0 && (int) $request->input('height') <= 0) {
            $request->merge(['length' => null, 'width' => null, 'height' => null]);
        }
        $d = $request->validate([
            'product_id' => 'nullable|integer',
            'existing_item_id' => 'nullable|integer',
            'name' => 'required|string|min:2|max:226',
            'description' => 'required|string|min:1|max:3000',
            'category_id' => 'required|integer',
            'brand_id' => 'nullable|integer',
            'brand_name' => 'nullable|string|max:100',
            // With variations the price/stock live on each model; the item-level ones are then optional.
            'price' => 'exclude_with:models|required|integer|min:100',
            'stock' => 'exclude_with:models|required|integer|min:0|max:999999',
            'sku' => 'nullable|string|max:100',
            'weight' => 'required|integer|min:1|max:1000000', // grams
            'length' => 'nullable|integer|min:1|max:1000', 'width' => 'nullable|integer|min:1|max:1000', 'height' => 'nullable|integer|min:1|max:1000',
            'image_ids' => 'required|array|min:1|max:9', 'image_ids.*' => 'string',
            'video_upload_id' => 'nullable|string|max:150',
            'attributes' => 'nullable|array',
            'attributes.*.id' => 'required|integer',
            'attributes.*.value_ids' => 'nullable|array', 'attributes.*.value_ids.*' => 'integer',
            'attributes.*.text' => 'nullable|string|max:200',
            'attributes.*.unit' => 'nullable|string|max:20',
            'tiers' => 'nullable|array|max:2',
            'tiers.*.name' => 'required|string|max:14',
            'tiers.*.options' => 'required|array|min:1|max:50', 'tiers.*.options.*' => 'string|max:20',
            'models' => 'nullable|array|max:50',
            'models.*.index' => 'required|array', 'models.*.index.*' => 'integer',
            'models.*.price' => 'required|integer|min:100',
            'models.*.stock' => 'required|integer|min:0',
            'models.*.sku' => 'nullable|string|max:100',
            'gtin' => ['nullable', 'string', 'regex:/^(00|\d{8,14})$/'],
            'condition' => 'nullable|in:NEW,USED',
            'dangerous' => 'nullable|boolean',
            'pre_order' => 'nullable|boolean',
            'days_to_ship' => 'nullable|integer|min:1|max:90',
            'wholesale' => 'nullable|array|max:5',
            'wholesale.*.min' => 'required|integer|min:2',
            'wholesale.*.max' => 'required|integer|gte:wholesale.*.min',
            'wholesale.*.price' => 'required|integer|min:100',
            'channels' => 'nullable|array', 'channels.*' => 'string|max:100', // courier names to enable; omitted = all
            'status' => 'nullable|in:NORMAL,UNLIST', // Simpan & Tampilkan / Simpan & Arsipkan
            'publish_at' => 'nullable|date', // Jadwal Ditampilkan, WIB "YYYY-MM-DDTHH:mm" (item stays UNLIST until then)
        ], [
            'name.required' => 'Nama produk wajib diisi.',
            'name.min' => 'Nama produk minimal 2 karakter.',
            'name.max' => 'Nama produk maksimal 226 karakter.',
            'description.required' => 'Deskripsi produk wajib diisi.',
            'description.max' => 'Deskripsi produk maksimal 3000 karakter.',
            'category_id.required' => 'Kategori produk wajib dipilih.',
            'price.min' => 'Harga minimal Rp100.',
            'price.required' => 'Harga wajib diisi.',
            'stock.required' => 'Stok wajib diisi.',
            'stock.min' => 'Stok tidak boleh kurang dari 0.',
            'weight.required' => 'Berat produk wajib diisi.',
            'weight.min' => 'Berat produk minimal 1 gram.',
            'length.min' => 'Ukuran panjang paket minimal 1 cm (atau kosongkan semua ukuran paket).',
            'width.min' => 'Ukuran lebar paket minimal 1 cm (atau kosongkan semua ukuran paket).',
            'height.min' => 'Ukuran tinggi paket minimal 1 cm (atau kosongkan semua ukuran paket).',
            'image_ids.required' => 'Tambahkan minimal 1 foto produk.',
            'image_ids.min' => 'Tambahkan minimal 1 foto produk.',
            'tiers.*.name.required' => 'Nama variasi wajib diisi.',
            'tiers.*.name.max' => 'Nama variasi maksimal 14 karakter.',
            'tiers.*.options.required' => 'Opsi variasi wajib diisi.',
            'tiers.*.options.*.max' => 'Nama opsi variasi maksimal 20 karakter.',
            'models.*.price.min' => 'Harga setiap variasi minimal Rp100.',
            'models.*.price.required' => 'Harga setiap variasi wajib diisi.',
            'models.*.stock.required' => 'Stok setiap variasi wajib diisi.',
            'models.*.stock.min' => 'Stok variasi tidak boleh kurang dari 0.',
            'gtin.regex' => 'GTIN harus 8–14 digit angka, atau centang "Produk tanpa GTIN".',
        ]);
        if (! empty($d['models'])) {
            $d['price'] = min(array_column($d['models'], 'price'));
            $d['stock'] = array_sum(array_column($d['models'], 'stock'));
        }
        // Shopee accepts now+1h … now+90d, to the minute.
        $scheduled = ! empty($d['publish_at']) ? \Carbon\Carbon::parse($d['publish_at'], \App\Support\Dates::TZ)->startOfMinute() : null;
        abort_if($scheduled && $scheduled->lt(now()->addHour()), 422, 'Jadwal tayang minimal 1 jam dari sekarang.');
        abort_if($scheduled && $scheduled->gt(now()->addDays(90)), 422, 'Jadwal tayang maksimal 90 hari lagi.');
        $product = ! empty($d['product_id']) ? Product::where('user_id', $request->user()->id)->find($d['product_id']) : null;
        $api = new ShopeeClient;
        $itemId = (int) ($d['existing_item_id'] ?? 0);

        try {
            $channels = $api->enabledChannels($shop);
            abort_if(! $channels, 422, 'Belum ada jasa kirim aktif di toko ini. Aktifkan di Seller Centre → Pengaturan Pengiriman.');
            if (isset($d['channels'])) {
                $channels = array_values(array_filter($channels, fn ($c) => in_array($c['name'], $d['channels'], true)));
                abort_if(! $channels, 422, 'Produk tidak dapat disimpan jika tidak ada jasa kirim yang aktif.');
            }
            $models = $d['models'] ?? [];
            $gtin = $d['gtin'] ?? null;
            $itemStatus = $scheduled ? 'UNLIST' : ($d['status'] ?? 'NORMAL');
            $item = array_filter([
                'item_name' => $d['name'], 'description' => $d['description'], 'category_id' => $d['category_id'],
                'original_price' => $models ? min(array_column($models, 'price')) : $d['price'],
                'seller_stock' => [['stock' => $models ? array_sum(array_column($models, 'stock')) : $d['stock']]],
                'item_sku' => $d['sku'] ?? null,
                'weight' => round($d['weight'] / 1000, 3),
                'dimension' => ! empty($d['length']) && ! empty($d['width']) && ! empty($d['height'])
                    ? ['package_length' => $d['length'], 'package_width' => $d['width'], 'package_height' => $d['height']] : null,
                'item_status' => $itemStatus,
                'scheduled_publish_time' => $scheduled?->timestamp,
                'condition' => $d['condition'] ?? 'NEW',
                'item_dangerous' => isset($d['dangerous']) ? (int) $d['dangerous'] : null,
                'gtin_code' => $models ? null : $gtin,
                'pre_order' => ['is_pre_order' => (bool) ($d['pre_order'] ?? false)] + (isset($d['days_to_ship']) ? ['days_to_ship' => $d['days_to_ship']] : []),
                'wholesale' => ! empty($d['wholesale'])
                    ? collect($d['wholesale'])->map(fn ($w) => ['min_count' => $w['min'], 'max_count' => $w['max'], 'unit_price' => $w['price']])->all() : null,
                'image' => ['image_id_list' => array_values($d['image_ids'])],
                'video_upload_id' => ! empty($d['video_upload_id']) ? [$d['video_upload_id']] : null,
                'brand' => ['brand_id' => (int) ($d['brand_id'] ?? 0), 'original_brand_name' => $d['brand_name'] ?? 'NoBrand'],
                'logistic_info' => collect($channels)->map(fn ($c) => ['logistic_id' => $c['id'], 'enabled' => true])->all(),
                'attribute_list' => $this->attributeList($d['attributes'] ?? []) ?: null,
            ], fn ($v) => $v !== null);

            if (! $itemId) {
                $res = $api->addItem($shop, $item);
                $itemId = (int) ($res['item_id'] ?? 0);
                abort_unless($itemId, 422, 'Shopee tidak mengembalikan item_id.');
            }

            $listings = [];
            $imageUrl = $this->imageUrl($d['image_ids'][0]);
            if ($models && ! empty($d['tiers'])) {
                $tierPayload = collect($d['tiers'])->map(fn ($t) => ['name' => $t['name'], 'option_list' => collect($t['options'])->map(fn ($o) => ['option' => $o])->all()])->all();
                $modelPayload = collect($models)->map(fn ($m) => array_filter(['tier_index' => $m['index'], 'original_price' => $m['price'], 'seller_stock' => [['stock' => $m['stock']]], 'model_sku' => $m['sku'] ?? null, 'gtin_code' => $gtin], fn ($v) => $v !== null))->all();
                $tierRes = null;
                // Shopee often needs ~5s after add_item before init_tier_variation can attach variations to the new item.
                for ($attempt = 0; $attempt < 4; $attempt++) {
                    try {
                        $tierRes = $api->initTierVariation($shop, $itemId, $tierPayload, $modelPayload);
                        break;
                    } catch (ShopeeException $e) {
                        if ($attempt < 3 && preg_match('/still being created|wait \d+ second|item.*not.*found|system busy|try again/i', $e->getMessage())) {
                            if (! app()->runningUnitTests()) {
                                sleep(5);
                            }
                            continue;
                        }
                        throw $e;
                    }
                }
                foreach ($tierRes['model'] ?? [] as $i => $m) {
                    $src = collect($models)->first(fn ($x) => $x['index'] === ($m['tier_index'] ?? null)) ?? $models[$i] ?? [];
                    $listings[] = $this->listing($shop, $product, $itemId, (int) $m['model_id'], $d['name'],
                        collect($m['tier_index'] ?? [])->map(fn ($ti, $k) => $d['tiers'][$k]['options'][$ti] ?? '')->implode(', '), $src['sku'] ?? null, $src['price'] ?? $d['price'], $src['stock'] ?? 0, $imageUrl, $itemStatus);
                }
            } else {
                $listings[] = $this->listing($shop, $product, $itemId, 0, $d['name'], null, $d['sku'] ?? null, $d['price'], $d['stock'], $imageUrl, $itemStatus);
            }
        } catch (ShopeeException $e) {
            return response()->json([
                'message' => self::translateShopeeError($e->getMessage()),
                'item_id' => $itemId ?: null,
            ], 422);
        }
        ActivityLog::record($request->user(), 'marketplace.publish', "Upload \"{$d['name']}\" ke {$shop->shop_name}");

        return ['item_id' => $itemId, 'listings' => count($listings), 'shop' => $shop->shop_name, 'status' => $itemStatus]
            + $this->liveStatus($api, $shop, $itemId);
    }

    /** Translate common Shopee API English errors into clear Bahasa Indonesia for the seller. */
    public static function translateShopeeError(string $msg): string
    {
        $m = trim($msg);
        $rules = [
            '/still being created.*wait (\d+) second/i' => 'Produk sedang diproses oleh Shopee. Mohon tunggu $1 detik lalu klik "Ulangi yang gagal" untuk menyimpan variasi.',
            '/duplicate.*item.*name|item name.*already exists/i' => 'Nama produk sudah pernah digunakan di toko Shopee ini. Ubah sedikit nama produk lalu coba lagi.',
            '/logistic.*not.*valid|logistic.*empty|channel.*not.*enabled|no.*valid.*logistic/i' => 'Jasa kirim yang dipilih tidak aktif atau tidak mendukung berat/ukuran paket produk ini di toko Shopee.',
            '/weight.*exceed|weight.*limit|weight.*invalid/i' => 'Berat produk melebihi batas ekspedisi yang dipilih atau tidak valid.',
            '/dimension.*invalid|package_length|package_width|package_height/i' => 'Ukuran paket (panjang, lebar, tinggi) tidak valid atau melebihi batas ekspedisi.',
            '/mandatory.*attribute|attribute.*mandatory|attribute.*invalid/i' => 'Masih ada atribut spesifikasi wajib Shopee yang belum diisi atau tidak valid.',
            '/brand.*invalid|brand.*mandatory/i' => 'Merek produk wajib dipilih untuk kategori ini.',
            '/category.*invalid|category.*not.*leaf/i' => 'Kategori produk tidak valid atau belum dipilih sampai sub-kategori paling akhir.',
            '/price.*limit|price.*range|price.*exceed|price.*difference/i' => 'Harga produk atau selisih harga antar variasi melewati batas ketentuan Shopee.',
            '/image.*invalid|image.*download|image.*size/i' => 'Foto produk gagal diproses oleh Shopee. Pastikan format JPG/PNG dan ukuran sesuai.',
            '/gtin.*invalid|gtin.*mandatory/i' => 'Kode GTIN tidak valid. Gunakan 8–14 digit angka atau centang "Produk tanpa GTIN".',
            '/tier_variation.*option.*duplicate/i' => 'Ada nama opsi variasi yang kembar/duplikat. Pastikan setiap opsi variasi berbeda.',
            '/token.*expired|invalid.*access_token|permission.*denied/i' => 'Sesi koneksi toko Shopee sudah kedaluwarsa. Hubungkan ulang toko di menu Daftar Toko.',
        ];
        foreach ($rules as $pattern => $idText) {
            if (preg_match($pattern, $m)) {
                return preg_replace($pattern, $idText, $m) ?: $idText;
            }
        }

        return $m;
    }

    /**
     * "Salin ke toko lain": an existing Shopee product of one of the seller's shops, shaped like the publish form
     * (name, description, category, brand, attributes, photos, variations, price/stock, shipping, others) so it can be
     * uploaded to other shops. Photos come back as URLs; the form re-uploads them.
     */
    public function source(Request $request, MarketplaceShop $shop, int $item)
    {
        abort_unless($shop->user_id === $request->user()->id && $shop->status === 'active', 404);
        $api = new ShopeeClient;
        try {
            $b = $api->itemBaseInfo($shop, [$item])[0] ?? null;
            abort_unless($b, 404, 'Produk tidak ditemukan di Shopee.');
            $m = ! empty($b['has_model']) ? $api->modelList($shop, $item) : [];
        } catch (ShopeeException $e) {
            abort(422, 'Gagal mengambil produk dari Shopee: '.$e->getMessage());
        }
        $price = fn ($x) => (int) round($x['price_info'][0]['original_price'] ?? 0);
        $stock = fn ($x) => (int) collect($x['stock_info_v2']['seller_stock'] ?? [])->sum('stock');
        $desc = ($b['description_type'] ?? 'normal') === 'extended'
            ? collect($b['description_info']['extended_description']['field_list'] ?? [])->where('field_type', 'text')->pluck('text')->implode("\n")
            : ($b['description'] ?? '');
        $tiers = collect($m['tier_variation'] ?? [])->map(fn ($t) => ['name' => mb_substr($t['name'] ?? '', 0, 14), 'options' => collect($t['option_list'] ?? [])->map(fn ($o) => mb_substr($o['option'] ?? '', 0, 20))->all()])->all();
        $models = $tiers ? collect($m['model'] ?? [])->map(fn ($x) => ['index' => $x['tier_index'] ?? [], 'price' => $price($x), 'stock' => $stock($x), 'sku' => $x['model_sku'] ?? ''])->values()->all() : [];
        $gtin = $b['gtin_code'] ?? collect($m['model'] ?? [])->pluck('gtin_code')->filter()->first();

        return [
            'source' => ['shop_id' => $shop->id, 'shop_name' => $shop->shop_name, 'item_id' => $item],
            'name' => $b['item_name'] ?? '',
            'description' => $desc,
            'category' => $this->leaves($shop)->get((int) ($b['category_id'] ?? 0)),
            'brand_id' => (int) ($b['brand']['brand_id'] ?? 0),
            'images' => array_slice($b['image']['image_url_list'] ?? [], 0, 9),
            'attributes' => collect($b['attribute_list'] ?? [])->map(fn ($a) => [
                'id' => (int) $a['attribute_id'],
                'value_ids' => collect($a['attribute_value_list'] ?? [])->pluck('value_id')->filter()->map(fn ($v) => (int) $v)->values()->all(),
                'text' => collect($a['attribute_value_list'] ?? [])->firstWhere('value_id', 0)['original_value_name'] ?? '',
                'unit' => collect($a['attribute_value_list'] ?? [])->firstWhere('value_id', 0)['value_unit'] ?? '',
            ])->values()->all(),
            'price' => $price($b), 'stock' => $stock($b), 'sku' => $b['item_sku'] ?? '',
            'tiers' => $tiers, 'models' => $models,
            'wholesale' => collect($b['wholesales'] ?? [])->map(fn ($w) => ['min' => (int) $w['min_count'], 'max' => (int) $w['max_count'], 'price' => (int) round($w['unit_original_price'] ?? $w['unit_price'] ?? 0)])->values()->all(),
            'weight' => (int) round(((float) ($b['weight'] ?? 0)) * 1000), // kg → gram
            'dims' => [
                'length' => ! empty($b['dimension']['package_length']) && (int) $b['dimension']['package_length'] > 0 ? (string) (int) $b['dimension']['package_length'] : '',
                'width' => ! empty($b['dimension']['package_width']) && (int) $b['dimension']['package_width'] > 0 ? (string) (int) $b['dimension']['package_width'] : '',
                'height' => ! empty($b['dimension']['package_height']) && (int) $b['dimension']['package_height'] > 0 ? (string) (int) $b['dimension']['package_height'] : '',
            ],
            'condition' => strtoupper($b['condition'] ?? 'NEW') === 'USED' ? 'USED' : 'NEW',
            'dangerous' => (bool) ($b['item_dangerous'] ?? false),
            'pre_order' => (bool) ($b['pre_order']['is_pre_order'] ?? false),
            'days_to_ship' => (int) ($b['pre_order']['days_to_ship'] ?? 0),
            'gtin' => $gtin && $gtin !== '00' ? $gtin : null,
        ];
    }

    /** "Cek status": what Shopee says about an uploaded item now (it may still be under review, or banned). */
    public function status(Request $request, MarketplaceShop $shop, int $item)
    {
        abort_unless($shop->user_id === $request->user()->id, 404);

        return ['item_id' => $item] + $this->liveStatus(new ShopeeClient, $shop, $item);
    }

    /**
     * Shopee's own item_status (NORMAL / UNLIST / REVIEWING / BANNED / SHOPEE_DELETE / SELLER_DELETE) and the Seller Centre
     * link, so "berhasil" never hides an item that Shopee is still checking or has blocked. Listing rows follow it.
     */
    private function liveStatus(ShopeeClient $api, MarketplaceShop $shop, int $itemId): array
    {
        $out = ['seller_centre_url' => "https://seller.shopee.co.id/portal/product/{$itemId}"];
        try {
            $info = $api->itemBaseInfo($shop, [$itemId])[0] ?? null;
        } catch (Throwable) {
            return $out;
        }
        if (! $info) {
            return $out + ['shopee_status' => 'NOT_FOUND'];
        }
        $st = $info['item_status'] ?? null;
        if ($st) {
            MarketplaceListing::where('marketplace_shop_id', $shop->id)->where('item_id', $itemId)->update(['status' => $st]);
        }

        return $out + ['shopee_status' => $st];
    }

    /** UI attribute answers → Shopee attribute_list (dropdown ids, or value_id 0 + text/timestamp). */
    private function attributeList(array $answers): array
    {
        return collect($answers)->map(function ($a) {
            $values = collect($a['value_ids'] ?? [])->filter()->map(fn ($id) => ['value_id' => (int) $id]);
            if ($values->isEmpty() && isset($a['text']) && trim($a['text']) !== '') {
                $values = collect([array_filter(['value_id' => 0, 'original_value_name' => trim($a['text']), 'value_unit' => $a['unit'] ?? null], fn ($v) => $v !== null)]);
            }

            return $values->isEmpty() ? null : ['attribute_id' => (int) $a['id'], 'attribute_value_list' => $values->values()->all()];
        })->filter()->values()->all();
    }

    private function listing(MarketplaceShop $shop, ?Product $product, int $itemId, int $modelId, string $name, ?string $modelName, ?string $sku, int $price, int $stock, string $image, string $status = 'NORMAL'): MarketplaceListing
    {
        $l = MarketplaceListing::firstOrNew(['marketplace_shop_id' => $shop->id, 'item_id' => $itemId, 'model_id' => $modelId]);
        $l->user_id = $shop->user_id;
        $l->fill(['product_id' => $product?->id, 'name' => $name, 'model_name' => $modelName, 'sku' => $sku, 'price' => $price, 'stock' => $stock, 'status' => $status, 'image' => $image,
            'cost_price' => $l->cost_price ?? $product?->cost_price])->save();
        \App\Support\MasterStock::autoAttach($l);

        return $l;
    }

    private function imageUrl(string $id): string
    {
        return "https://cf.shopee.co.id/file/{$id}";
    }

    private function anyShop(Request $request): MarketplaceShop
    {
        $shop = $request->user()->marketplaceShops()->where('status', 'active')->where('is_demo', false)
            ->when($request->query('shop'), fn ($q, $id) => $q->whereKey($id))->first();
        abort_unless($shop, 422, 'Hubungkan toko Shopee dulu.');

        return $shop;
    }
}
