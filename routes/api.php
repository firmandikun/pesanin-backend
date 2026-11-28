<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\ShopeePushController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\LandingPageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PublicCheckoutController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/google', [AuthController::class, 'google'])->middleware('throttle:20,1');

// Public checkout pages
// Shopee: OAuth return and Live Push (called by Shopee, not by the app).
Route::get('/shopee/callback', [MarketplaceController::class, 'callback']);
Route::get('/shopee/authorize', [MarketplaceController::class, 'authorizeShared'])->middleware('throttle:30,1');
Route::get('/whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'verifyApp']);
Route::post('/whatsapp/webhook', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'receiveApp'])->middleware('throttle:1200,1');
Route::get('/whatsapp/webhook/{token}', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'verify']);
Route::post('/whatsapp/webhook/{token}', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'receive'])->middleware('throttle:600,1');
Route::get('/meta/callback', [\App\Http\Controllers\Api\MetaAdsController::class, 'callback']);
Route::post('/meta/data-deletion', [\App\Http\Controllers\LegalController::class, 'metaDeletion'])->middleware('throttle:30,1');
Route::get('/brand/app-icon', fn () => response()->file(public_path('app-icon.png'), ['Cache-Control' => 'public, max-age=86400']));
Route::post('/shopee/push', ShopeePushController::class)->middleware('throttle:600,1');
// Midtrans callback (called by the provider, not by the app).
Route::post('/midtrans/notify', [\App\Http\Controllers\Api\IntegrationController::class, 'midtransNotify'])->middleware('throttle:600,1');
// KiriminAja webhook for waybill issuance & delivery status updates.
Route::post('/webhook', [\App\Http\Controllers\Api\IntegrationController::class, 'kiriminajaWebhook'])->middleware('throttle:120,1');
Route::post('/kiriminaja/webhook', [\App\Http\Controllers\Api\IntegrationController::class, 'kiriminajaWebhook'])->middleware('throttle:120,1');

Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('/regions', [PublicCheckoutController::class, 'regions']);
    Route::get('/pages/{slug}', [LandingPageController::class, 'publicShow']);
    Route::post('/pages/{slug}/track', [FeatureController::class, 'track'])->withoutMiddleware('throttle:60,1')->middleware('throttle:240,1');
    Route::get('/wa/{slug}', [FeatureController::class, 'rotatorPublic']);
    Route::get('/confirm/{store}', [FeatureController::class, 'confirmPage']);
    Route::post('/confirm/{store}', [FeatureController::class, 'confirmSubmit'])->middleware('throttle:10,1');
    Route::get('/products/{slug}', [PublicCheckoutController::class, 'show']);
    Route::post('/products/{slug}/quote', [PublicCheckoutController::class, 'quote']);
    Route::post('/products/{slug}/abandoned', [PublicCheckoutController::class, 'abandoned']);
    Route::post('/products/{slug}/checkout', [PublicCheckoutController::class, 'checkout']);
    Route::get('/orders/{code}/payment', [PublicCheckoutController::class, 'paymentStatus']);
    Route::get('/digital/{token}', [PublicCheckoutController::class, 'digital']);
    Route::get('/digital/{token}/file', [PublicCheckoutController::class, 'digitalFile']);
    Route::get('/tutorials', [\App\Http\Controllers\Api\ContentController::class, 'tutorials']);
    Route::post('/tutorials/{slug}/view', [\App\Http\Controllers\Api\ContentController::class, 'tutorialView']);
    Route::get('/blog', [\App\Http\Controllers\Api\ContentController::class, 'blog']);
    Route::get('/blog/{slug}', [\App\Http\Controllers\Api\ContentController::class, 'blogPost']);
    Route::get('/announcements', [\App\Http\Controllers\Api\ContentController::class, 'announcements']);
    Route::get('/site', [\App\Http\Controllers\Api\ContentController::class, 'site']);
    // Scan Out from the phone (QR on the dashboard)
    Route::get('/scan-out/{token}', [\App\Http\Controllers\Api\PublicScanOutController::class, 'show']);
    Route::post('/scan-out/{token}/scan', [\App\Http\Controllers\Api\PublicScanOutController::class, 'scan'])->withoutMiddleware('throttle:60,1')->middleware('throttle:300,1');
    Route::post('/scan-out/{token}/undo', [\App\Http\Controllers\Api\PublicScanOutController::class, 'undo']);
    Route::post('/scan-out/{token}/finish', [\App\Http\Controllers\Api\PublicScanOutController::class, 'finish']);
    Route::post('/scan-out/{token}/pack/{order}', [\App\Http\Controllers\Api\PublicScanOutController::class, 'pack'])->whereNumber('order')->withoutMiddleware('throttle:60,1')->middleware('throttle:300,1');
});

// Pesanin platform admin (users.is_admin).
Route::middleware(['auth:sanctum', 'member', 'admin'])->prefix('admin')->controller(\App\Http\Controllers\Api\AdminController::class)->group(function () {
    Route::get('/overview', 'overview');
    Route::get('/activation', 'activation');
    Route::get('/users', 'users');
    Route::get('/users/{user}', 'user');
    Route::put('/users/{user}', 'updateUser');
    Route::get('/invoices', 'invoices');
    Route::post('/invoices/{invoice}/paid', 'markInvoicePaid');
    Route::get('/shops', 'shops');
    Route::get('/platform', 'platform');
    Route::put('/platform', 'updatePlatform');
    Route::get('/blog-categories', 'blogCategories');
    Route::post('/uploads', 'upload');
    Route::get('/content/{type}', 'contentIndex');
    Route::get('/content/{type}/{id}', 'contentShow')->whereNumber('id');
    Route::post('/content/{type}', 'contentStore');
    Route::put('/content/{type}/{id}', 'contentUpdate')->whereNumber('id');
    Route::delete('/content/{type}/{id}', 'contentDestroy')->whereNumber('id');
});

Route::middleware(['auth:sanctum', 'member', 'admin'])->prefix('admin/dropship')->controller(\App\Http\Controllers\Api\DropshipController::class)->group(function () {
    Route::get('/products', 'adminIndex');
    Route::post('/products', 'adminStore');
    Route::get('/products/{dropshipProduct}', 'adminShow');
    Route::put('/products/{dropshipProduct}', 'adminUpdate');
    Route::post('/products/{dropshipProduct}/duplicate', 'adminDuplicate');
    Route::delete('/products/{dropshipProduct}', 'adminDestroy');
    Route::get('/orders', 'adminOrders');
    Route::post('/orders/{order}/ship', 'adminShip');
    Route::post('/orders/{order}/modal-paid', 'adminModalPaid');
    Route::post('/videos', 'adminUploadVideo')->middleware('throttle:20,1');
});

Route::middleware(['auth:sanctum', 'member', 'subscribed'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/sidebar', function (\Illuminate\Http\Request $request) {
        $n = \App\Support\SidebarConfig::forUser($request->user());

        return ['items' => $n['items'], 'hidden' => $n['hidden']];
    });
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/dropship', [\App\Http\Controllers\Api\DropshipController::class, 'index']);
    Route::get('/dropship/products/{dropshipProduct}', [\App\Http\Controllers\Api\DropshipController::class, 'show']);
    Route::get('/dropship/products/{dropshipProduct}/slides/{pack}/zip', [\App\Http\Controllers\Api\DropshipController::class, 'slidesZip'])->middleware('throttle:20,1');
    Route::put('/dropship/profile', [\App\Http\Controllers\Api\DropshipController::class, 'saveProfile']);
    Route::post('/dropship/adoptions', [\App\Http\Controllers\Api\DropshipController::class, 'adopt']);
    Route::delete('/dropship/adoptions/{adoption}', [\App\Http\Controllers\Api\DropshipController::class, 'destroyAdoption']);
    Route::put('/me', [AuthController::class, 'updateProfile']);
    Route::put('/me/password', [AuthController::class, 'updatePassword']);
    Route::get('/followup-variables', fn () => \App\Support\DefaultTemplates::VARIABLES);

    Route::get('/dashboard', [ReportController::class, 'dashboard']);
    Route::get('/reports/sales', [ReportController::class, 'sales']);
    Route::get('/reports/statistics', [ReportController::class, 'statistics']);
    Route::get('/reports/profit', [ReportController::class, 'profit']);
    Route::get('/reports/research', [ReportController::class, 'research']);
    Route::get('/expenses', [\App\Http\Controllers\Api\ExpenseController::class, 'index']);
    Route::post('/expenses', [\App\Http\Controllers\Api\ExpenseController::class, 'store']);
    Route::put('/expenses/{expense}', [\App\Http\Controllers\Api\ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [\App\Http\Controllers\Api\ExpenseController::class, 'destroy']);

    Route::get('/marketplace/shops', [MarketplaceController::class, 'shops']);
    Route::post('/marketplace/shopee/connect', [MarketplaceController::class, 'connect']);
    Route::post('/marketplace/shopee/share-link', [MarketplaceController::class, 'shareLink']);
    Route::post('/marketplace/shopee/complete', [MarketplaceController::class, 'completeFromUrl']);
    Route::post('/marketplace/shopee/demo', [MarketplaceController::class, 'demo']);
    Route::post('/marketplace/shops/{shop}/sync', [MarketplaceController::class, 'sync'])->middleware('throttle:60,1');
    Route::get('/marketplace/returns', [MarketplaceController::class, 'returns']);
    Route::get('/marketplace/returns/{id}', [\App\Http\Controllers\Api\ShopeeAfterSalesController::class, 'show'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/marketplace/returns/{id}/act', [\App\Http\Controllers\Api\ShopeeAfterSalesController::class, 'act'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/marketplace/orders/{id}', [\App\Http\Controllers\Api\ShopeeAfterSalesController::class, 'detail'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/marketplace/orders/{id}/cancel', [\App\Http\Controllers\Api\ShopeeAfterSalesController::class, 'cancel'])->whereNumber('id')->middleware('throttle:20,1');
    Route::put('/marketplace/orders/{id}/note', [\App\Http\Controllers\Api\ShopeeAfterSalesController::class, 'note'])->whereNumber('id')->middleware('throttle:60,1');
    Route::get('/marketplace/balance', [MarketplaceController::class, 'balance']);
    Route::get('/marketplace/balance/pending', fn (\Illuminate\Http\Request $r) => \App\Support\PendingFunds::breakdown($r->user(), $r->integer('shop_id') ?: null));
    Route::get('/marketplace/money-check', fn (\Illuminate\Http\Request $r) => \App\Support\MoneyCheck::scan($r->user(), $r->integer('shop_id') ?: null));
    Route::get('/marketplace/shops/{shop}/transactions', [MarketplaceController::class, 'transactions']);
    Route::get('/marketplace/shops/{shop}/transactions/export', [MarketplaceController::class, 'exportTransactions']);
    Route::get('/marketplace/shops/{shop}/statement', [MarketplaceController::class, 'statement']);
    Route::delete('/marketplace/shops/{shop}', [MarketplaceController::class, 'disconnect']);
    // Chat inbox (all sources). Shopee for now; Meta channels plug in next to it.
    Route::get('/chat', [\App\Http\Controllers\Api\ShopeeChatController::class, 'conversations']);
    Route::get('/chat/shopee/{shop}/buyer/{toId}', [\App\Http\Controllers\Api\ShopeeChatController::class, 'buyerVars'])->whereNumber('toId');
    Route::get('/chat/shopee/{shop}/{conversation}', [\App\Http\Controllers\Api\ShopeeChatController::class, 'messages']);
    Route::post('/chat/shopee/{shop}', [\App\Http\Controllers\Api\ShopeeChatController::class, 'send'])->middleware('throttle:30,1');
    Route::post('/chat/shopee/{shop}/image', [\App\Http\Controllers\Api\ShopeeChatController::class, 'sendImage'])->middleware('throttle:20,1');
    Route::get('/chat/whatsapp/{phone}/{waId}', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'messages']);
    Route::post('/chat/whatsapp/{phone}', [\App\Http\Controllers\Api\WhatsAppChatController::class, 'send'])->middleware('throttle:30,1');
    Route::get('/chat/{channel}/{page}/{conversation}', [\App\Http\Controllers\Api\MetaChatController::class, 'messages'])->whereIn('channel', ['facebook', 'instagram']);
    Route::post('/chat/{channel}/{page}', [\App\Http\Controllers\Api\MetaChatController::class, 'send'])->whereIn('channel', ['facebook', 'instagram'])->middleware('throttle:30,1');
    // WhatsApp: connect by signing in with Facebook
    Route::post('/whatsapp/connect', [\App\Http\Controllers\Api\WhatsAppConnectController::class, 'connect']);
    Route::get('/whatsapp/connect/pending', [\App\Http\Controllers\Api\WhatsAppConnectController::class, 'pending']);
    Route::post('/whatsapp/connect/select', [\App\Http\Controllers\Api\WhatsAppConnectController::class, 'select']);
    // WhatsApp broadcast + message templates
    Route::get('/broadcasts/status', [\App\Http\Controllers\Api\BroadcastController::class, 'status']);
    Route::post('/broadcasts/preview', [\App\Http\Controllers\Api\BroadcastController::class, 'preview']);
    Route::get('/broadcasts', [\App\Http\Controllers\Api\BroadcastController::class, 'index']);
    Route::post('/broadcasts', [\App\Http\Controllers\Api\BroadcastController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/broadcasts/{broadcast}', [\App\Http\Controllers\Api\BroadcastController::class, 'show']);
    Route::post('/broadcasts/{broadcast}/cancel', [\App\Http\Controllers\Api\BroadcastController::class, 'cancel']);
    Route::get('/whatsapp/templates', [\App\Http\Controllers\Api\BroadcastController::class, 'templates']);
    Route::post('/whatsapp/templates', [\App\Http\Controllers\Api\BroadcastController::class, 'storeTemplate'])->middleware('throttle:20,1');
    Route::delete('/whatsapp/templates/{name}', [\App\Http\Controllers\Api\BroadcastController::class, 'deleteTemplate']);
    // Inbox collaboration + CRM
    Route::get('/chat/thread', [\App\Http\Controllers\Api\InboxController::class, 'show']);
    Route::put('/chat/thread', [\App\Http\Controllers\Api\InboxController::class, 'update']);
    Route::post('/chat/thread/notes', [\App\Http\Controllers\Api\InboxController::class, 'addNote']);
    Route::delete('/chat/notes/{note}', [\App\Http\Controllers\Api\InboxController::class, 'deleteNote']);
    Route::post('/chat/thread/customer', [\App\Http\Controllers\Api\InboxController::class, 'link']);
    Route::get('/chat-labels', [\App\Http\Controllers\Api\InboxController::class, 'labels']);
    Route::post('/chat-labels', [\App\Http\Controllers\Api\InboxController::class, 'storeLabel']);
    Route::put('/chat-labels/{label}', [\App\Http\Controllers\Api\InboxController::class, 'updateLabel']);
    Route::delete('/chat-labels/{label}', [\App\Http\Controllers\Api\InboxController::class, 'deleteLabel']);
    Route::get('/quick-replies', [\App\Http\Controllers\Api\InboxController::class, 'quickReplies']);
    Route::post('/quick-replies', [\App\Http\Controllers\Api\InboxController::class, 'storeQuickReply']);
    Route::put('/quick-replies/{reply}', [\App\Http\Controllers\Api\InboxController::class, 'updateQuickReply']);
    Route::delete('/quick-replies/{reply}', [\App\Http\Controllers\Api\InboxController::class, 'deleteQuickReply']);
    // Pembelian & Supplier (purchase orders, goods received → stock + HPP, payables)
    Route::controller(\App\Http\Controllers\Api\PurchasingController::class)->group(function () {
        Route::get('/suppliers', 'suppliers');
        Route::post('/suppliers', 'storeSupplier');
        Route::put('/suppliers/{supplier}', 'updateSupplier');
        Route::delete('/suppliers/{supplier}', 'deleteSupplier');
        Route::get('/purchases/catalog', 'catalog');
        Route::get('/purchases', 'index');
        Route::post('/purchases', 'store');
        Route::get('/purchases/{purchase}', 'show')->whereNumber('purchase');
        Route::put('/purchases/{purchase}', 'update')->whereNumber('purchase');
        Route::post('/purchases/{purchase}/receive', 'receive')->whereNumber('purchase');
        Route::post('/purchases/{purchase}/cancel', 'cancel')->whereNumber('purchase');
        Route::post('/purchases/{purchase}/payments', 'pay')->whereNumber('purchase');
        Route::delete('/purchases/{purchase}/payments/{payment}', 'deletePayment')->whereNumber('purchase');
    });
    Route::get('/customers/reorder', [\App\Http\Controllers\Api\ReorderController::class, 'index']);
    Route::post('/customers/reorder/contacted', [\App\Http\Controllers\Api\ReorderController::class, 'contacted']);
    Route::put('/customers/reorder/settings', [\App\Http\Controllers\Api\ReorderController::class, 'settings']);
    Route::get('/customers', [\App\Http\Controllers\Api\InboxController::class, 'customers']);
    Route::post('/customers', [\App\Http\Controllers\Api\InboxController::class, 'storeCustomer']);
    Route::get('/customers/{customer}', [\App\Http\Controllers\Api\InboxController::class, 'showCustomer']);
    Route::put('/customers/{customer}', [\App\Http\Controllers\Api\InboxController::class, 'updateCustomer']);
    Route::delete('/customers/{customer}', [\App\Http\Controllers\Api\InboxController::class, 'deleteCustomer']);
    Route::get('/reports/ai-diagnosis', [\App\Http\Controllers\Api\AiDiagnosisController::class, 'index']);
    Route::post('/reports/ai-diagnosis', [\App\Http\Controllers\Api\AiDiagnosisController::class, 'analyze'])->middleware('throttle:10,1');
    Route::get('/meta-chat', [\App\Http\Controllers\Api\MetaChatController::class, 'status']);
    Route::post('/meta-chat/connect', [\App\Http\Controllers\Api\MetaChatController::class, 'connect']);
    Route::delete('/meta-chat', [\App\Http\Controllers\Api\MetaChatController::class, 'disconnect']);
    Route::get('/marketplace/publish/categories', [\App\Http\Controllers\Api\ShopeePublishController::class, 'categories']);
    Route::get('/marketplace/publish/categories/{category}', [\App\Http\Controllers\Api\ShopeePublishController::class, 'category'])->whereNumber('category');
    Route::get('/marketplace/publish/recommend', [\App\Http\Controllers\Api\ShopeePublishController::class, 'recommend'])->middleware('throttle:60,1');
    Route::get('/marketplace/publish/shops', [\App\Http\Controllers\Api\ShopeePublishController::class, 'shops']);
    Route::post('/marketplace/publish/images', [\App\Http\Controllers\Api\ShopeePublishController::class, 'images'])->middleware('throttle:30,1');
    Route::post('/marketplace/publish/video', [\App\Http\Controllers\Api\ShopeePublishController::class, 'video'])->middleware('throttle:10,1');
    Route::get('/marketplace/publish/{shop}/items/{item}/source', [\App\Http\Controllers\Api\ShopeePublishController::class, 'source'])->whereNumber('item')->middleware('throttle:60,1');
    Route::get('/marketplace/publish/{shop}/items/{item}', [\App\Http\Controllers\Api\ShopeePublishController::class, 'status'])->whereNumber('item')->middleware('throttle:60,1');
    Route::post('/marketplace/publish/{shop}', [\App\Http\Controllers\Api\ShopeePublishController::class, 'publish'])->middleware('throttle:120,1');
    Route::get('/meta-ads', [\App\Http\Controllers\Api\MetaAdsController::class, 'status']);
    Route::post('/meta-ads/connect', [\App\Http\Controllers\Api\MetaAdsController::class, 'connect']);
    Route::post('/meta-ads/token', [\App\Http\Controllers\Api\MetaAdsController::class, 'token'])->middleware('throttle:10,1');
    Route::put('/meta-ads/accounts', [\App\Http\Controllers\Api\MetaAdsController::class, 'accounts']);
    Route::post('/meta-ads/sync', [\App\Http\Controllers\Api\MetaAdsController::class, 'sync'])->middleware('throttle:10,1');
    Route::get('/meta-ads/report', [\App\Http\Controllers\Api\MetaAdsController::class, 'report']);
    Route::get('/meta-ads/destinations', [\App\Http\Controllers\Api\MetaAdsController::class, 'destinations']);
    Route::get('/meta-ads/campaigns', [\App\Http\Controllers\Api\MetaAdsController::class, 'campaigns']);
    Route::post('/meta-ads/campaigns', [\App\Http\Controllers\Api\MetaAdsController::class, 'createCampaign'])->middleware('throttle:20,1');
    Route::patch('/meta-ads/campaigns/{campaignId}', [\App\Http\Controllers\Api\MetaAdsController::class, 'updateCampaign'])->whereNumber('campaignId')->middleware('throttle:30,1');
    Route::delete('/meta-ads', [\App\Http\Controllers\Api\MetaAdsController::class, 'disconnect']);
    Route::get('/marketplace/ads', [MarketplaceController::class, 'ads']);
    Route::get('/marketplace/ads/create/options', [\App\Http\Controllers\Api\ShopeeAdsCreateController::class, 'options']);
    Route::get('/marketplace/ads/create/suggest', [\App\Http\Controllers\Api\ShopeeAdsCreateController::class, 'suggest']);
    Route::post('/marketplace/ads/create', [\App\Http\Controllers\Api\ShopeeAdsCreateController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/marketplace/health', [\App\Http\Controllers\Api\ShopeeHealthController::class, 'index'])->middleware('throttle:30,1');
    Route::get('/marketplace/reviews', [\App\Http\Controllers\Api\ShopeeReviewController::class, 'index']);
    Route::get('/marketplace/reviews/products', [\App\Http\Controllers\Api\ShopeeReviewController::class, 'products']);
    Route::post('/marketplace/reviews/sync', [\App\Http\Controllers\Api\ShopeeReviewController::class, 'sync'])->middleware('throttle:10,1');
    Route::post('/marketplace/reviews/reply', [\App\Http\Controllers\Api\ShopeeReviewController::class, 'reply'])->middleware('throttle:30,1');
    Route::get('/marketplace/ads/guard', [\App\Http\Controllers\Api\AdsGuardController::class, 'index']);
    Route::get('/marketplace/ads/ai', [\App\Http\Controllers\Api\AdsAiController::class, 'index']);
    Route::post('/marketplace/ads/ai', [\App\Http\Controllers\Api\AdsAiController::class, 'analyze'])->middleware('throttle:10,1');
    Route::post('/marketplace/ads/guard/{campaign}', [\App\Http\Controllers\Api\AdsGuardController::class, 'action'])->whereNumber('campaign')->middleware('throttle:30,1');
    // Scan Out: bukti serah terima paket ke ekspedisi
    Route::controller(\App\Http\Controllers\Api\ScanOutController::class)->prefix('scan-out/sessions')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{session}', 'show')->whereNumber('session');
        Route::post('/{session}/scan', 'scan')->whereNumber('session')->middleware('throttle:300,1');
        Route::post('/{session}/finish', 'finish')->whereNumber('session');
        Route::post('/{session}/pack/{order}', 'pack')->whereNumber(['session', 'order'])->middleware('throttle:300,1');
        Route::post('/{session}/rematch', 'rematch')->whereNumber('session')->middleware('throttle:6,1');
        Route::delete('/{session}/parcels/{parcel}', 'destroyParcel')->whereNumber(['session', 'parcel']);
    });
    Route::get('/marketplace/listings', [MarketplaceController::class, 'listings']);
    // Promosi toko Shopee (diskon, voucher, paket diskon, kombo hemat, flash sale, produk unggulan)
    Route::controller(\App\Http\Controllers\Api\ShopeePromotionController::class)->prefix('marketplace/promotions')->group(function () {
        Route::get('/flash/slots', 'timeSlots')->middleware('throttle:30,1');
        Route::put('/top_picks/{id}/toggle', 'toggleTopPicks')->middleware('throttle:20,1');
        Route::get('/{type}', 'index')->middleware('throttle:60,1');
        Route::post('/{type}', 'store')->middleware('throttle:20,1');
        Route::post('/{type}/{id}/stop', 'stop')->middleware('throttle:20,1');
    });
    Route::controller(\App\Http\Controllers\Api\ShopeeProductToolsController::class)->prefix('marketplace/listings')->group(function () {
        Route::get('/boost', 'boostIndex');
        Route::put('/boost/{shop}', 'boostUpdate')->whereNumber('shop')->middleware('throttle:30,1');
        Route::post('/boost/{shop}/run', 'boostRun')->whereNumber('shop')->middleware('throttle:10,1');
        Route::post('/stats/sync', 'statsSync')->middleware('throttle:4,1');
        Route::get('/performance', 'performance');
        Route::get('/margins', 'margins');
        Route::get('/bundle-suggestions', 'bundleSuggestions')->middleware('throttle:30,1');
        Route::get('/order-hours', 'orderHours')->middleware('throttle:30,1');
        Route::get('/active-promotions', 'activePromotions')->middleware('throttle:30,1');
        Route::post('/prices', 'prices')->middleware('throttle:20,1');
        Route::post('/unlist', 'unlist')->middleware('throttle:20,1');
        Route::get('/text/{shop}/{item}', 'itemText')->whereNumber(['shop', 'item']);
        Route::post('/sku/preview', 'skuPreview')->middleware('throttle:30,1');
        Route::post('/sku/save', 'skuSave')->middleware('throttle:10,1');
        Route::put('/text/{shop}/{item}', 'updateText')->whereNumber(['shop', 'item'])->middleware('throttle:30,1');
    });
    Route::put('/marketplace/listings/{listing}/link', [MarketplaceController::class, 'link']);
    Route::put('/marketplace/listings/{listing}/stock', [MarketplaceController::class, 'stock']);
    // Stok master lintas toko
    Route::controller(\App\Http\Controllers\Api\StockMasterController::class)->prefix('marketplace/stock-masters')->group(function () {
        Route::get('/', 'index');
        Route::post('/generate', 'generate');
        Route::post('/', 'store');
        Route::put('/{master}', 'update');
        Route::post('/{master}/listings', 'attach');
        Route::delete('/{master}/listings/{listing}', 'detach');
        Route::post('/{master}/push', 'push');
        Route::get('/{master}/movements', 'movements');
        Route::delete('/{master}', 'destroy');
    });
    Route::put('/marketplace/listings/{listing}/cost', [MarketplaceController::class, 'cost']);
    Route::get('/marketplace/listing-costs', [MarketplaceController::class, 'costGroups']);
    Route::post('/marketplace/listing-costs', [MarketplaceController::class, 'bulkCost']);
    Route::get('/labels', [LabelController::class, 'data']);
    Route::get('/labels/shopee/options', [LabelController::class, 'shopeeOptions']);
    Route::post('/labels/shopee/ship', [LabelController::class, 'shopeeShip']);
    Route::post('/labels/shopee/cancel', [LabelController::class, 'shopeeCancel']);
    Route::post('/labels/shopee/refresh', [LabelController::class, 'shopeeRefresh']);
    Route::post('/labels/shopee/pdf', [LabelController::class, 'shopeePdf']);
    Route::post('/labels/shopee/booking-pdf', [LabelController::class, 'shopeeBookingPdf']);
    Route::post('/labels/shopee/print-jobs', [LabelController::class, 'startPrintJob'])->middleware('throttle:10,1');
    Route::get('/labels/shopee/print-jobs/{job}', [LabelController::class, 'printJob'])->whereNumber('job');
    Route::get('/labels/shopee/print-jobs/{job}/file', [LabelController::class, 'printJobFile'])->whereNumber('job');

    Route::get('/categories', [ProductController::class, 'categories']);
    Route::post('/uploads', [ProductController::class, 'upload']);
    Route::post('/uploads/digital', [ProductController::class, 'uploadDigital']);
    Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate']);
    Route::apiResource('products', ProductController::class);

    Route::post('/landing-pages/ai', [LandingPageController::class, 'generateAi'])->middleware('throttle:10,1');
    Route::get('/landing-pages/ai/{job}', [LandingPageController::class, 'generateAiStatus']);
    Route::post('/landing-pages/{landingPage}/duplicate', [LandingPageController::class, 'duplicate']);
    Route::apiResource('landing-pages', LandingPageController::class);

    Route::get('/orders/export', [OrderController::class, 'export']);
    Route::get('/orders/{order}/shipping-rates', [\App\Http\Controllers\Api\IntegrationController::class, 'orderRates']);
    Route::post('/orders/{order}/ship/cancel', [\App\Http\Controllers\Api\IntegrationController::class, 'cancelShipment'])->middleware('throttle:20,1');
    Route::post('/orders/book', [\App\Http\Controllers\Api\IntegrationController::class, 'book'])->middleware('throttle:20,1');
    Route::post('/orders/bulk', [OrderController::class, 'bulkUpdate']);
    Route::post('/orders/{order}/digital-send', [OrderController::class, 'digitalSend'])->middleware('throttle:20,1');
    Route::get('/research/status', [\App\Http\Controllers\Api\MarketResearchController::class, 'status']);
    Route::get('/research/shopee', [\App\Http\Controllers\Api\MarketResearchController::class, 'shopee'])->middleware('throttle:30,1');
    Route::get('/research/trends', [\App\Http\Controllers\Api\MarketResearchController::class, 'trends'])->middleware('throttle:20,1');
    Route::get('/research/trends/related', [\App\Http\Controllers\Api\MarketResearchController::class, 'related'])->middleware('throttle:20,1');
    Route::get('/integrations', [\App\Http\Controllers\Api\IntegrationController::class, 'index']);
    Route::put('/integrations/{provider}', [\App\Http\Controllers\Api\IntegrationController::class, 'save']);
    Route::delete('/integrations/{provider}', [\App\Http\Controllers\Api\IntegrationController::class, 'destroy']);
    // Telegram bot for the scheduled Shopee AWB PDF.
    Route::get('/telegram', [\App\Http\Controllers\Api\TelegramController::class, 'show']);
    Route::put('/telegram', [\App\Http\Controllers\Api\TelegramController::class, 'save'])->middleware('throttle:10,1');
    Route::post('/telegram/detect', [\App\Http\Controllers\Api\TelegramController::class, 'detect'])->middleware('throttle:10,1');
    Route::post('/telegram/test', [\App\Http\Controllers\Api\TelegramController::class, 'test'])->middleware('throttle:10,1');
    Route::post('/telegram/send-awb', [\App\Http\Controllers\Api\TelegramController::class, 'sendNow'])->middleware('throttle:5,1');
    Route::delete('/telegram', [\App\Http\Controllers\Api\TelegramController::class, 'destroy']);
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);
    Route::post('/notifications/test', [\App\Http\Controllers\Api\NotificationController::class, 'test']);
    Route::post('/push/subscribe', [\App\Http\Controllers\Api\NotificationController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [\App\Http\Controllers\Api\NotificationController::class, 'unsubscribe']);
    Route::get('/orders/{order}/tracking', [OrderController::class, 'tracking']);
    Route::post('/orders/{order}/followup', [OrderController::class, 'followup']);
    Route::apiResource('orders', OrderController::class);

    Route::get('/balance', [FeatureController::class, 'balance']);
    Route::get('/balance/summary', [FeatureController::class, 'balanceSummary']);
    Route::post('/shipping/preview', [FeatureController::class, 'shippingPreview']);
    Route::get('/landing-reports', [FeatureController::class, 'landingReports']);
    Route::get('/inventory', [FeatureController::class, 'inventory']);
    Route::get('/product-reports', [FeatureController::class, 'productReports']);
    Route::get('/transactions', [FeatureController::class, 'transactions']);
    Route::get('/affiliate-stats', [FeatureController::class, 'affiliateStats']);
    Route::get('/rotator-stats', [FeatureController::class, 'rotatorStats']);
    Route::get('/rotator-chats', [FeatureController::class, 'rotatorChats']);
    Route::get('/billing', [BillingController::class, 'show']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:20,1');
    Route::put('/billing/auto-renew', [BillingController::class, 'autoRenew']);
    Route::post('/billing/free', [BillingController::class, 'chooseFree']);
    Route::put('/billing/track', [BillingController::class, 'track']);
    Route::post('/billing/invoices/{invoice}/cancel', [BillingController::class, 'cancel']);
    Route::post('/billing/invoices/{invoice}/simulate-pay', [BillingController::class, 'simulatePay']);
    Route::get('/user-settings', [FeatureController::class, 'userSettings']);
    Route::put('/user-settings', [FeatureController::class, 'updateUserSettings']);
    Route::post('/pixels/{id}/capi-test', [\App\Http\Controllers\Api\TrackingController::class, 'capiTest'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/r/{type}', [ResourceController::class, 'index']);
    Route::post('/r/{type}', [ResourceController::class, 'store']);
    Route::put('/r/{type}/{id}', [ResourceController::class, 'update'])->whereNumber('id');
    Route::delete('/r/{type}/{id}', [ResourceController::class, 'destroy'])->whereNumber('id');

    Route::get('/addresses', [SettingsController::class, 'addresses']);
    Route::post('/addresses', [SettingsController::class, 'saveAddress']);
    Route::put('/addresses/{address}', [SettingsController::class, 'saveAddress']);
    Route::delete('/addresses/{address}', [SettingsController::class, 'deleteAddress']);
    Route::get('/followup-templates', [SettingsController::class, 'templates']);
    Route::put('/followup-templates/{template}', [SettingsController::class, 'updateTemplate']);
    Route::post('/followup-templates', [SettingsController::class, 'createTemplate']);
    Route::delete('/followup-templates/{template}', [SettingsController::class, 'deleteTemplate']);
    Route::get('/banned-devices', [SettingsController::class, 'banned']);
    Route::post('/banned-devices', [SettingsController::class, 'ban']);
    Route::delete('/banned-devices/{device}', [SettingsController::class, 'unban']);
    Route::get('/activity-logs', [SettingsController::class, 'activity']);
});
