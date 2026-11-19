<?php

namespace App\Support\Shopee;

use App\Models\MarketplaceShop;
use Illuminate\Support\Facades\Http;

/**
 * Thin Shopee Open Platform v2 client.
 * Signing: HMAC-SHA256(partner_id . path . timestamp [. access_token . shop_id], partner_key).
 */
class ShopeeClient
{
    private int $partnerId;

    private string $partnerKey;

    private string $host;

    public function __construct()
    {
        $this->partnerId = (int) config('services.shopee.partner_id');
        $this->partnerKey = (string) config('services.shopee.partner_key');
        $this->host = (string) config('services.shopee.host');
    }

    public static function configured(): bool
    {
        return config('services.shopee.partner_id') && config('services.shopee.partner_key');
    }

    public function sign(string $path, int $timestamp, ?string $accessToken = null, ?int $shopId = null): string
    {
        $base = $this->partnerId.$path.$timestamp.($accessToken ?? '').($shopId ?? '');

        return hash_hmac('sha256', $base, $this->partnerKey);
    }

    /** URL the seller opens to authorise one of their shops for this app. */
    public function authUrl(string $state): string
    {
        $path = '/api/v2/shop/auth_partner';
        $ts = time();
        $redirect = config('services.shopee.redirect').'?state='.urlencode($state);

        return $this->host.$path.'?'.http_build_query([
            'partner_id' => $this->partnerId, 'timestamp' => $ts, 'sign' => $this->sign($path, $ts), 'redirect' => $redirect,
        ]);
    }

    public function getToken(string $code, int $shopId): array
    {
        return $this->publicPost('/api/v2/auth/token/get', ['code' => $code, 'shop_id' => $shopId, 'partner_id' => $this->partnerId]);
    }

    public function refreshToken(string $refreshToken, int $shopId): array
    {
        return $this->publicPost('/api/v2/auth/access_token/get', ['refresh_token' => $refreshToken, 'shop_id' => $shopId, 'partner_id' => $this->partnerId]);
    }

    /** Verify a Live Push call: Authorization = HMAC-SHA256(url|body, partner_key). */
    public function verifyPush(string $url, string $body, ?string $authorization): bool
    {
        // Live Push is signed with its own "Live Push Partner Key" (console → Push Mechanism); fall back to the API partner key.
        $key = (string) (config('services.shopee.push_key') ?: $this->partnerKey);

        return $authorization !== null && $key !== '' && hash_equals(hash_hmac('sha256', $url.'|'.$body, $key), $authorization);
    }

    // ── Shop-level API ──────────────────────────────────────────────

    public function shopInfo(MarketplaceShop $shop): array
    {
        return $this->get($shop, '/api/v2/shop/get_shop_info');
    }

    public function itemList(MarketplaceShop $shop, int $offset, int $pageSize = 100): array
    {
        return $this->get($shop, '/api/v2/product/get_item_list', [
            'offset' => $offset, 'page_size' => $pageSize, 'item_status' => ['NORMAL', 'UNLIST'],
        ])['response'] ?? [];
    }

    /* ---------- Kesehatan akun (v2.account_health) ---------- */

    public function shopPerformance(MarketplaceShop $shop): array
    {
        return $this->get($shop, '/api/v2/account_health/get_shop_performance')['response'] ?? [];
    }

    /** Penalty point records of the current quarter (all pages, up to 500). */
    public function penaltyPoints(MarketplaceShop $shop): array
    {
        return $this->pages($shop, '/api/v2/account_health/get_penalty_point_history', 'penalty_point_list');
    }

    public function lateOrders(MarketplaceShop $shop): array
    {
        return $this->pages($shop, '/api/v2/account_health/get_late_orders', 'late_order_list');
    }

    public function listingsWithIssues(MarketplaceShop $shop): array
    {
        return $this->pages($shop, '/api/v2/account_health/get_listings_with_issues', 'listing_list');
    }

    /** page_no / page_size lists of account_health, read until total_count (max 5 pages of 100). */
    private function pages(MarketplaceShop $shop, string $path, string $key): array
    {
        $out = [];
        for ($page = 1; $page <= 5; $page++) {
            $r = $this->get($shop, $path, ['page_no' => $page, 'page_size' => 100])['response'] ?? [];
            $out = [...$out, ...($r[$key] ?? [])];
            if (count($out) >= (int) ($r['total_count'] ?? 0) || empty($r[$key])) {
                break;
            }
        }

        return $out;
    }

    /* ---------- Ulasan pembeli (v2.product.get_comment / reply_comment) ---------- */

    /** One page of the shop's product reviews, newest first. */
    public function comments(MarketplaceShop $shop, string $cursor = '', int $pageSize = 100, ?int $itemId = null): array
    {
        return $this->get($shop, '/api/v2/product/get_comment', array_filter(['cursor' => $cursor, 'page_size' => $pageSize, 'item_id' => $itemId], fn ($v) => $v !== null))['response'] ?? [];
    }

    /**
     * Reply to reviews. @param array<int|string,string> $replies comment_id => text (≤ 100)
     * @return array<string,string> comment_id => Shopee's reason, for the replies it refused
     */
    public function replyComments(MarketplaceShop $shop, array $replies): array
    {
        $res = $this->post($shop, '/api/v2/product/reply_comment', [
            'comment_list' => collect($replies)->map(fn ($text, $id) => ['comment_id' => (int) $id, 'comment' => $text])->values()->all(),
        ]);

        return collect($res['response']['result_list'] ?? [])->filter(fn ($r) => ! empty($r['fail_error']))
            ->mapWithKeys(fn ($r) => [(string) $r['comment_id'] => (string) ($r['fail_message'] ?: $r['fail_error'])])->all();
    }

    /** @param int[] $itemIds up to 50 */
    public function itemBaseInfo(MarketplaceShop $shop, array $itemIds): array
    {
        return $this->get($shop, '/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $itemIds)])['response']['item_list'] ?? [];
    }

    public function modelList(MarketplaceShop $shop, int $itemId): array
    {
        return $this->get($shop, '/api/v2/product/get_model_list', ['item_id' => $itemId])['response'] ?? [];
    }

    public function updateStock(MarketplaceShop $shop, int $itemId, int $modelId, int $stock): array
    {
        return $this->post($shop, '/api/v2/product/update_stock', [
            'item_id' => $itemId,
            'stock_list' => [['model_id' => $modelId, 'seller_stock' => [['stock' => $stock]]]],
        ]);
    }

    /**
     * Set the stock of several models of one item in one call. @param array<int,int> $stocks model_id => stock
     * @return array<int,string> model_id => Shopee's reason, for the models it refused (empty = all set)
     */
    public function updateStocks(MarketplaceShop $shop, int $itemId, array $stocks): array
    {
        $res = $this->post($shop, '/api/v2/product/update_stock', [
            'item_id' => $itemId,
            'stock_list' => collect($stocks)->map(fn ($stock, $modelId) => ['model_id' => (int) $modelId, 'seller_stock' => [['stock' => max(0, (int) $stock)]]])->values()->all(),
        ]);

        return collect($res['response']['failure_list'] ?? [])->mapWithKeys(fn ($f) => [(int) ($f['model_id'] ?? 0) => (string) ($f['failed_reason'] ?? 'ditolak Shopee')])->all();
    }

    public function orderList(MarketplaceShop $shop, int $from, int $to, string $cursor = '', string $field = 'create_time', ?string $status = null): array
    {
        return $this->get($shop, '/api/v2/order/get_order_list', array_filter([
            'time_range_field' => $field, 'time_from' => $from, 'time_to' => $to, 'page_size' => 100, 'cursor' => $cursor,
            'order_status' => $status, 'response_optional_fields' => 'order_status',
        ], fn ($v) => $v !== null))['response'] ?? [];
    }

    /** Shopee "Pengiriman Kilat" reservations (bookings). Same windowing rules as orders (≤ 15 days). */
    public function bookingList(MarketplaceShop $shop, int $from, int $to, string $cursor = '', string $field = 'create_time', ?string $status = null): array
    {
        return $this->get($shop, '/api/v2/order/get_booking_list', array_filter([
            'time_range_field' => $field, 'time_from' => $from, 'time_to' => $to, 'page_size' => 100, 'cursor' => $cursor,
            'booking_status' => $status,
        ], fn ($v) => $v !== null))['response'] ?? [];
    }

    /** Return / refund requests created in [from, to] (≤ 15 days). page_no starts at 0. */
    public function returnList(MarketplaceShop $shop, int $from, int $to, int $pageNo = 0): array
    {
        return $this->get($shop, '/api/v2/returns/get_return_list', [
            'page_no' => $pageNo, 'page_size' => 100, 'create_time_from' => $from, 'create_time_to' => $to,
        ])['response'] ?? [];
    }

    /** @param string[] $bookingSns up to 50 */
    public function bookingDetail(MarketplaceShop $shop, array $bookingSns): array
    {
        return $this->get($shop, '/api/v2/order/get_booking_detail', [
            'booking_sn_list' => implode(',', $bookingSns),
            'response_optional_fields' => 'item_list,shipping_carrier,cancel_by,cancel_reason,fulfillment_flag,pickup_done_time',
        ])['response']['booking_list'] ?? [];
    }

    public function bookingTrackingNumber(MarketplaceShop $shop, string $bookingSn): ?string
    {
        return $this->get($shop, '/api/v2/logistics/get_booking_tracking_number', ['booking_sn' => $bookingSn])['response']['tracking_number'] ?? null;
    }

    /** @param string[] $orderSns up to 50 */
    public function orderDetail(MarketplaceShop $shop, array $orderSns): array
    {
        return $this->get($shop, '/api/v2/order/get_order_detail', [
            'order_sn_list' => implode(',', $orderSns),
            // Report Shopee's "Tertunda" orders (still under Shopee review) as PENDING instead of READY_TO_SHIP,
            // so they are not arranged until Shopee releases them.
            'request_order_status_pending' => 'true',
            'response_optional_fields' => 'buyer_user_id,pending_terms,buyer_username,recipient_address,item_list,total_amount,shipping_carrier,checkout_shipping_carrier,payment_method,estimated_shipping_fee,actual_shipping_fee,pay_time,package_list,cod,cancel_reason,buyer_cancel_reason,fulfillment_flag',
        ])['response']['order_list'] ?? [];
    }

    /** Seller wallet transactions; each carries the wallet's `current_balance` right after it. Range ≤ 15 days. */
    public function walletTransactions(MarketplaceShop $shop, int $pageSize = 20, ?int $from = null, ?int $to = null): array
    {
        $params = array_filter(['page_no' => 0, 'page_size' => $pageSize, 'create_time_from' => $from, 'create_time_to' => $to], fn ($v) => $v !== null);

        return $this->get($shop, '/api/v2/payment/get_wallet_transaction_list', $params)['response']['transaction_list'] ?? [];
    }

    /** Seller Centre's "Income Overview": pending_amount (not yet released) and released_amount. */
    public function incomeOverview(MarketplaceShop $shop): array
    {
        $r = $this->get($shop, '/api/v2/payment/get_income_overview');

        return $r['response']['total_income'] ?? $r['total_income'] ?? [];
    }

    public function escrowDetail(MarketplaceShop $shop, string $orderSn): array
    {
        return $this->get($shop, '/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn])['response'] ?? [];
    }

    public function shippingParameter(MarketplaceShop $shop, string $orderSn): array
    {
        return $this->get($shop, '/api/v2/logistics/get_shipping_parameter', ['order_sn' => $orderSn])['response'] ?? [];
    }

    public function shipOrder(MarketplaceShop $shop, string $orderSn, array $method): array
    {
        return $this->post($shop, '/api/v2/logistics/ship_order', ['order_sn' => $orderSn] + $method);
    }

    public function bookingShippingParameter(MarketplaceShop $shop, string $bookingSn): array
    {
        return $this->get($shop, '/api/v2/logistics/get_booking_shipping_parameter', ['booking_sn' => $bookingSn])['response'] ?? [];
    }

    public function shipBooking(MarketplaceShop $shop, string $bookingSn, array $method): array
    {
        return $this->post($shop, '/api/v2/logistics/ship_booking', ['booking_sn' => $bookingSn] + $method);
    }

    /** Respond to a buyer's cancellation request (IN_CANCEL): $operation = 'REJECT' or 'ACCEPT'. */
    public function handleBuyerCancellation(MarketplaceShop $shop, string $orderSn, string $operation = 'REJECT'): array
    {
        return $this->post($shop, '/api/v2/order/handle_buyer_cancellation', [
            'order_sn' => $orderSn,
            'operation' => strtoupper($operation),
        ])['response'] ?? [];
    }

    /** Courier checkpoints of a shipped order: [logistics_status, tracking_info[{update_time, description, logistics_status}]]. */
    public function trackingInfo(MarketplaceShop $shop, string $orderSn): array
    {
        return $this->get($shop, '/api/v2/logistics/get_tracking_info', ['order_sn' => $orderSn])['response'] ?? [];
    }

    public function trackingNumber(MarketplaceShop $shop, string $orderSn): ?string
    {
        return $this->get($shop, '/api/v2/logistics/get_tracking_number', ['order_sn' => $orderSn])['response']['tracking_number'] ?? null;
    }

    /** @param array<string, string|null> $orders order_sn => tracking number (Shopee rejects the document without it) */
    public function createShippingDocument(MarketplaceShop $shop, array $orders): array
    {
        return $this->post($shop, '/api/v2/logistics/create_shipping_document', [
            'order_list' => array_map(
                fn ($sn) => array_filter(['order_sn' => (string) $sn, 'tracking_number' => $orders[$sn], 'shipping_document_type' => 'THERMAL_AIR_WAYBILL']),
                array_keys($orders),
            ),
        ])['response']['result_list'] ?? [];
    }

    public function shippingDocumentResult(MarketplaceShop $shop, array $orderSns): array
    {
        return $this->post($shop, '/api/v2/logistics/get_shipping_document_result', [
            'order_list' => array_map(fn ($sn) => ['order_sn' => $sn, 'shipping_document_type' => 'THERMAL_AIR_WAYBILL'], $orderSns),
        ])['response']['result_list'] ?? [];
    }

    /** Returns the PDF bytes of the air waybills. */
    public function downloadShippingDocument(MarketplaceShop $shop, array $orderSns): string
    {
        $res = $this->request($shop, 'POST', '/api/v2/logistics/download_shipping_document', [
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
            'order_list' => array_map(fn ($sn) => ['order_sn' => $sn], $orderSns),
        ], raw: true);
        if (! is_string($res)) {
            throw new ShopeeException('Dokumen resi belum siap, coba beberapa saat lagi.');
        }

        return $res;
    }

    /** @param array<string, string|null> $bookings booking_sn => tracking number. Same flow as orders, booking endpoints. */
    public function createBookingShippingDocument(MarketplaceShop $shop, array $bookings): array
    {
        return $this->post($shop, '/api/v2/logistics/create_booking_shipping_document', [
            'booking_list' => array_map(
                fn ($sn) => array_filter(['booking_sn' => (string) $sn, 'tracking_number' => $bookings[$sn], 'shipping_document_type' => 'THERMAL_AIR_WAYBILL']),
                array_keys($bookings),
            ),
        ])['response']['result_list'] ?? [];
    }

    public function bookingShippingDocumentResult(MarketplaceShop $shop, array $bookingSns): array
    {
        return $this->post($shop, '/api/v2/logistics/get_booking_shipping_document_result', [
            'booking_list' => array_map(fn ($sn) => ['booking_sn' => $sn, 'shipping_document_type' => 'THERMAL_AIR_WAYBILL'], $bookingSns),
        ])['response']['result_list'] ?? [];
    }

    /** PDF bytes of the reservations' air waybills. */
    public function downloadBookingShippingDocument(MarketplaceShop $shop, array $bookingSns): string
    {
        $res = $this->request($shop, 'POST', '/api/v2/logistics/download_booking_shipping_document', [
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
            'booking_list' => array_map(fn ($sn) => ['booking_sn' => $sn], $bookingSns),
        ], raw: true);
        if (! is_string($res)) {
            throw new ShopeeException('Dokumen resi belum siap, coba beberapa saat lagi.');
        }

        return $res;
    }

    /*
     * Seller chat (v2.sellerchat.*). Restricted module: callable in sandbox, but Shopee's docs list no app
     * type for live, so access must be granted by Shopee. Field names below were confirmed against sandbox
     * where possible (to_id is required to send); the rest follow Shopee's chat payloads.
     */
    public function chatConversations(MarketplaceShop $shop, int $pageSize = 25, string $type = 'all', ?string $nextNano = null): array
    {
        return $this->get($shop, '/api/v2/sellerchat/get_conversation_list', array_filter([
            'direction' => 'older', 'type' => $type, 'page_size' => $pageSize, 'next_timestamp_nano' => $nextNano,
        ]))['response'] ?? [];
    }

    public function chatUnreadCount(MarketplaceShop $shop): int
    {
        return (int) ($this->get($shop, '/api/v2/sellerchat/get_unread_conversation_count')['response']['total_unread_count'] ?? 0);
    }

    public function chatMessages(MarketplaceShop $shop, string $conversationId, int $pageSize = 50, ?string $offset = null): array
    {
        return $this->get($shop, '/api/v2/sellerchat/get_message', array_filter([
            'conversation_id' => $conversationId, 'page_size' => $pageSize, 'offset' => $offset,
        ]))['response'] ?? [];
    }

    public function chatSend(MarketplaceShop $shop, int $toId, string $text): array
    {
        return $this->post($shop, '/api/v2/sellerchat/send_message', ['to_id' => $toId, 'message_type' => 'text', 'content' => ['text' => $text]])['response'] ?? [];
    }

    public function chatRead(MarketplaceShop $shop, string $conversationId, string $lastMessageId): void
    {
        $this->post($shop, '/api/v2/sellerchat/read_conversation', ['conversation_id' => $conversationId, 'last_read_message_id' => $lastMessageId]);
    }

    /* ---------- Upload produk (v2.product.add_item and friends) ---------- */

    /** Full category tree (Indonesian names), cached a day per shop region. */
    public function categories(MarketplaceShop $shop): array
    {
        return \Illuminate\Support\Facades\Cache::remember("shopee:categories:{$this->partnerId}:".($shop->region ?: 'ID'), now()->addDay(), fn () => collect(
            $this->get($shop, '/api/v2/product/get_category', ['language' => 'id'])['response']['category_list'] ?? []
        )->map(fn ($c) => ['id' => (int) $c['category_id'], 'parent' => (int) $c['parent_category_id'], 'name' => $c['display_category_name'] ?: $c['original_category_name'], 'leaf' => ! $c['has_children']])->all());
    }

    /** Attributes of a leaf category; input_type 1 dropdown, 2 dropdown+custom, 3 text, 4/5 multi-select. */
    public function attributes(MarketplaceShop $shop, int $categoryId): array
    {
        return \Illuminate\Support\Facades\Cache::remember("shopee:attrs:{$this->partnerId}:{$categoryId}", now()->addDay(), function () use ($shop, $categoryId) {
            $tree = $this->get($shop, '/api/v2/product/get_attribute_tree', ['category_id_list' => $categoryId, 'language' => 'id'])['response']['list'][0]['attribute_tree'] ?? [];
            $label = fn ($x) => collect($x['multi_lang'] ?? [])->firstWhere('language', 'id')['value'] ?? preg_replace('/^\[S\]/', '', $x['name'] ?? '');

            return collect($tree)->map(fn ($a) => [
                'id' => (int) $a['attribute_id'], 'name' => $label($a), 'mandatory' => (bool) ($a['mandatory'] ?? false),
                'input_type' => (int) ($a['attribute_info']['input_type'] ?? 3),
                'validation' => (int) ($a['attribute_info']['input_validation_type'] ?? 0), // 1 int, 2 text, 3 float, 4 date
                'units' => $a['attribute_info']['attribute_unit_list'] ?? [],
                'values' => collect($a['attribute_value_list'] ?? [])->map(fn ($v) => ['id' => (int) $v['value_id'], 'name' => $label($v)])->values()->all(),
            ])->sortByDesc('mandatory')->values()->all();
        });
    }

    /** Brands allowed in a category; brand_id 0 = "Tidak ada merek". */
    public function brands(MarketplaceShop $shop, int $categoryId): array
    {
        return \Illuminate\Support\Facades\Cache::remember("shopee:brands:{$this->partnerId}:{$categoryId}", now()->addDay(), function () use ($shop, $categoryId) {
            $list = [];
            $offset = 0;
            $mandatory = false;
            do {
                $r = $this->get($shop, '/api/v2/product/get_brand_list', ['category_id' => $categoryId, 'status' => 1, 'offset' => $offset, 'page_size' => 100, 'language' => 'id'])['response'] ?? [];
                $mandatory = (bool) ($r['is_mandatory'] ?? $mandatory);
                foreach ($r['brand_list'] ?? [] as $b) {
                    $list[] = ['id' => (int) $b['brand_id'], 'name' => $b['display_brand_name'] ?: $b['original_brand_name']];
                }
                $offset = (int) ($r['next_offset'] ?? 0);
            } while (! empty($r['has_next_page']) && count($list) < 2000);

            return ['mandatory' => $mandatory, 'list' => $list];
        });
    }

    /** Couriers enabled in the shop (an item must use at least one). */
    public function enabledChannels(MarketplaceShop $shop): array
    {
        return collect($this->get($shop, '/api/v2/logistics/get_channel_list')['response']['logistics_channel_list'] ?? [])
            ->filter(fn ($c) => ($c['enabled'] ?? false) && empty($c['mask_channel_id']))
            ->map(fn ($c) => ['id' => (int) $c['logistics_channel_id'], 'name' => $c['logistics_channel_name']])->values()->all();
    }

    /** Category ids Shopee suggests for an item name (and optionally its cover image_id), best first. */
    public function categoryRecommend(MarketplaceShop $shop, string $name, ?string $imageId = null): array
    {
        return array_map('intval', $this->get($shop, '/api/v2/product/category_recommend', array_filter(['item_name' => $name, 'product_cover_image' => $imageId]))['response']['category_id'] ?? []);
    }

    public function itemLimit(MarketplaceShop $shop, int $categoryId): array
    {
        return $this->get($shop, '/api/v2/product/get_item_limit', ['category_id' => $categoryId])['response'] ?? [];
    }

    /** media_space upload: partner-level (no shop), multipart "image"; returns image_id usable in any shop. */
    public function uploadImage(string $bytes, string $filename): string
    {
        $path = '/api/v2/media_space/upload_image';
        $ts = time();
        $url = $this->host.$path.'?'.http_build_query(['partner_id' => $this->partnerId, 'timestamp' => $ts, 'sign' => $this->sign($path, $ts)]);
        $res = Http::timeout(60)->acceptJson()->attach('image', $bytes, $filename)->post($url);
        $json = $this->unwrap($res->json() ?? [], $res->status());
        $id = $json['response']['image_info']['image_id'] ?? $json['response']['image_info_list'][0]['image_info']['image_id'] ?? null;
        if (! $id) {
            throw new ShopeeException('Shopee tidak mengembalikan image_id untuk '.$filename);
        }

        return $id;
    }

    /**
     * Upload one MP4 through Shopee's multipart video flow.
     *
     * The returned upload id is the value accepted by v2.product.add_item. Completing the
     * upload starts Shopee's transcoding; it is not the same thing as merely uploading a file.
     */
    public function uploadVideo(string $bytes, string $filename): string
    {
        $init = $this->partnerPost('/api/v2/media/init_video_upload', [
            'file_md5' => md5($bytes),
            'file_size' => strlen($bytes),
        ]);
        $response = $init['response'] ?? [];
        $uploadId = (string) ($response['video_upload_id'] ?? '');
        if ($uploadId === '') {
            throw new ShopeeException('Shopee tidak mengembalikan video_upload_id.');
        }

        // Use Shopee's suggested size when present. Five MiB is a safe fallback and keeps
        // each request comfortably below common proxy limits.
        $partSize = max(1024 * 1024, (int) ($response['part_size'] ?? 5 * 1024 * 1024));
        $parts = str_split($bytes, $partSize);
        foreach ($parts as $index => $part) {
            $this->uploadVideoPart($uploadId, $index, $part, $filename);
        }

        $this->partnerPost('/api/v2/media/complete_video_upload', ['video_upload_id' => $uploadId], 120);

        return $uploadId;
    }

    private function uploadVideoPart(string $uploadId, int $partSeq, string $bytes, string $filename): void
    {
        $path = '/api/v2/media/upload_video_part';
        $ts = time();
        $url = $this->host.$path.'?'.http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'sign' => $this->sign($path, $ts),
        ]);
        $res = Http::timeout(120)->acceptJson()
            ->attach('video', $bytes, $filename, ['Content-Type' => 'video/mp4'])
            ->post($url, [
                'video_upload_id' => $uploadId,
                'part_seq' => $partSeq,
                'content_md5' => md5($bytes),
            ]);
        $this->unwrap($res->json() ?? [], $res->status());
    }

    public function addItem(MarketplaceShop $shop, array $item): array
    {
        return $this->post($shop, '/api/v2/product/add_item', $item)['response'] ?? [];
    }

    public function initTierVariation(MarketplaceShop $shop, int $itemId, array $tiers, array $models): array
    {
        return $this->post($shop, '/api/v2/product/init_tier_variation', ['item_id' => $itemId, 'tier_variation' => $tiers, 'model' => $models])['response'] ?? [];
    }

    /** Saldo iklan (Ads credit) in the shop's currency. */
    public function adsBalance(MarketplaceShop $shop): int
    {
        return (int) round($this->get($shop, '/api/v2/ads/get_total_balance')['response']['total_balance'] ?? 0);
    }

    /** All product-ads campaign ids of the shop. */
    /**
     * Edit a manual product ad (incl. ad groups): "pause", "resume" or "change_roas_target" (auto-bidding ads).
     * reference_id must be unique per request.
     */
    public function editManualAd(MarketplaceShop $shop, int $campaignId, string $action, array $extra = []): array
    {
        return $this->post($shop, '/api/v2/ads/edit_manual_product_ads', [
            'reference_id' => 'pesanin-'.$campaignId.'-'.\Illuminate\Support\Str::random(12),
            'campaign_id' => $campaignId, 'edit_action' => $action,
        ] + $extra)['response'] ?? [];
    }

    public function adCampaignIds(MarketplaceShop $shop): array
    {
        return array_column($this->adCampaignList($shop), 'campaign_id');
    }

    /** All product-ads campaigns [{campaign_id, ad_type}] of the shop. */
    public function adCampaignList(MarketplaceShop $shop): array
    {
        $list = [];
        $seen = [];
        // Query both 'all' and 'auto' to ensure automated product campaigns are always discovered.
        foreach (['all', 'auto'] as $type) {
            $offset = 0;
            do {
                $r = $this->get($shop, '/api/v2/ads/get_product_level_campaign_id_list', [
                    'ad_type' => $type, 'offset' => $offset, 'limit' => 100,
                ])['response'] ?? [];
                foreach ($r['campaign_list'] ?? [] as $c) {
                    $cid = (int) ($c['campaign_id'] ?? 0);
                    if (! $cid) {
                        continue;
                    }
                    if (! isset($seen[$cid])) {
                        $seen[$cid] = count($list);
                        $list[] = [
                            'campaign_id' => $cid,
                            'ad_type' => $type === 'auto' ? 'auto' : ($c['ad_type'] ?? null),
                        ];
                    } elseif ($type === 'auto') {
                        $list[$seen[$cid]]['ad_type'] = 'auto';
                    }
                }
                $offset += 100;
            } while (! empty($r['has_next_page']) && $offset < 5000);
        }

        return $list;
    }

    /** Name, status, budget and promoted item ids per campaign (max 100 ids per call). */
    public function adCampaignSettings(MarketplaceShop $shop, array $ids): array
    {
        return collect(array_chunk($ids, 100))->flatMap(fn ($chunk) => $this->get($shop, '/api/v2/ads/get_product_level_campaign_setting_info', [
            'campaign_id_list' => implode(',', $chunk), 'info_type_list' => '1,2,3,4',
        ])['response']['campaign_list'] ?? [])->all();
    }

    /**
     * Daily metrics per campaign (dates d-m-Y, range ≤ 1 month, start ≠ end).
     * Docs show `response` as an array of {shop_id, region, campaign_list[]}, but the live API (checked 2026-11-07)
     * returns a single object; both shapes are accepted.
     */
    public function adCampaignDailyPerformance(MarketplaceShop $shop, array $ids, string $startDate, string $endDate): array
    {
        return collect(array_chunk($ids, 100))->flatMap(function ($chunk) use ($shop, $startDate, $endDate) {
            $res = $this->get($shop, '/api/v2/ads/get_product_campaign_daily_performance', [
                'campaign_id_list' => implode(',', $chunk), 'start_date' => $startDate, 'end_date' => $endDate,
            ])['response'] ?? [];

            return array_is_list($res) ? collect($res)->flatMap(fn ($r) => $r['campaign_list'] ?? []) : ($res['campaign_list'] ?? []);
        })->all();
    }

    /** Shopee Ads daily spend; needs the Ads permission on the app. Dates are d-m-Y. */
    public function adsDailyPerformance(MarketplaceShop $shop, string $startDate, string $endDate): array
    {
        return $this->get($shop, '/api/v2/ads/get_all_cpc_ads_daily_performance', ['start_date' => $startDate, 'end_date' => $endDate])['response'] ?? [];
    }

    /** Generic signed shop-level call for endpoints without a dedicated helper. */
    public function call(MarketplaceShop $shop, string $method, string $path, array $data = []): array
    {
        return $this->request($shop, strtoupper($method), $path, $data);
    }


    // ── Transport ───────────────────────────────────────────────────

    private function get(MarketplaceShop $shop, string $path, array $params = []): array
    {
        return $this->request($shop, 'GET', $path, $params);
    }

    private function post(MarketplaceShop $shop, string $path, array $body = []): array
    {
        return $this->request($shop, 'POST', $path, $body);
    }

    private function request(MarketplaceShop $shop, string $method, string $path, array $data, bool $raw = false): array|string
    {
        $this->ensureFreshToken($shop);
        $ts = time();
        $common = [
            'partner_id' => $this->partnerId, 'timestamp' => $ts, 'access_token' => $shop->access_token,
            'shop_id' => $shop->shop_id, 'sign' => $this->sign($path, $ts, $shop->access_token, $shop->shop_id),
        ];

        $url = $this->host.$path.'?'.http_build_query($common);
        $http = Http::timeout(30)->acceptJson();
        $res = $method === 'GET'
            ? $http->get($url.'&'.$this->query($data))
            : $http->withBody(json_encode($data, JSON_UNESCAPED_SLASHES), 'application/json')->post($url);

        if ($raw && str_contains((string) $res->header('Content-Type'), 'pdf')) {
            return $res->body();
        }

        return $this->unwrap($res->json() ?? [], $res->status());
    }

    private function publicPost(string $path, array $body): array
    {
        $ts = time();
        $url = $this->host.$path.'?'.http_build_query(['partner_id' => $this->partnerId, 'timestamp' => $ts, 'sign' => $this->sign($path, $ts)]);
        $res = Http::timeout(30)->acceptJson()->withBody(json_encode($body), 'application/json')->post($url);

        return $this->unwrap($res->json() ?? [], $res->status());
    }

    /** Partner-level JSON endpoint (media uploads do not belong to a single shop). */
    private function partnerPost(string $path, array $body, int $timeout = 30): array
    {
        $ts = time();
        $url = $this->host.$path.'?'.http_build_query([
            'partner_id' => $this->partnerId,
            'timestamp' => $ts,
            'sign' => $this->sign($path, $ts),
        ]);
        $res = Http::timeout($timeout)->acceptJson()->withBody(json_encode($body, JSON_UNESCAPED_SLASHES), 'application/json')->post($url);

        return $this->unwrap($res->json() ?? [], $res->status());
    }

    /** Shopee wants repeated keys for array params (item_status=A&item_status=B). */
    private function query(array $params): string
    {
        $parts = [];
        foreach ($params as $k => $v) {
            foreach ((array) $v as $item) {
                $parts[] = urlencode($k).'='.urlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }

    private function unwrap(array $json, int $status): array
    {
        if (! empty($json['error'])) {
            throw new ShopeeException(($json['message'] ?? '') ?: $json['error'], $json['error']);
        }
        if ($status >= 400) {
            throw new ShopeeException("Shopee API HTTP {$status}");
        }

        return $json;
    }

    /** Access tokens live ~4 hours; refresh 5 minutes early. Refresh tokens live 30 days. */
    private function ensureFreshToken(MarketplaceShop $shop): void
    {
        if ($shop->is_demo) {
            throw new ShopeeException('Toko contoh tidak terhubung ke Shopee.');
        }
        if ($shop->access_expires_at && $shop->access_expires_at->subMinutes(5)->isFuture()) {
            return;
        }
        try {
            $t = $this->refreshToken($shop->refresh_token, $shop->shop_id);
        } catch (ShopeeException $e) {
            $shop->update(['status' => 'expired', 'last_error' => 'Otorisasi kedaluwarsa, hubungkan ulang toko.']);
            throw $e;
        }
        $shop->update([
            'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'],
            'access_expires_at' => now()->addSeconds($t['expire_in'] ?? 14400),
            'refresh_expires_at' => now()->addDays(30), 'status' => 'active', 'last_error' => null,
        ]);
    }
}
