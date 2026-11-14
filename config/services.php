<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Courier tracking for website/manual orders (binderbyte.com, free tier). Empty = status history only.
    // "Masuk dengan Google" (Google Identity Services ID token, verified server-side).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    // Midtrans for Pesanin's own subscription invoices (sellers connect their own keys in Pengaturan → Integrasi).
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'signature_key' => env('MIDTRANS_SIGNATURE_KEY'),
        'production' => (bool) env('MIDTRANS_PRODUCTION', false),
    ],

    // Web Push (VAPID) for new-order notifications. Generate keys: php artisan webpush:vapid
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@pesanin.test'),
    ],

    // Riset Pasar keys owned by Pesanin, shared by every seller (a seller may also connect their own).
    'shopee_affiliate' => ['app_id' => env('SHOPEE_AFFILIATE_APP_ID'), 'secret' => env('SHOPEE_AFFILIATE_SECRET')],
    'serpapi' => ['key' => env('SERPAPI_KEY')],

    // WhatsApp Cloud API webhooks must reach a public HTTPS URL; set this to a tunnel or the live domain.
    // verify_token: for the app-wide webhook https://<domain>/api/whatsapp/webhook set once in the Meta app.
    'whatsapp' => ['webhook_base' => env('WHATSAPP_WEBHOOK_BASE'), 'verify_token' => env('WHATSAPP_VERIFY_TOKEN')],

    // Meta Marketing API ("Login dengan Facebook" → ads_read). App from developers.facebook.com.
    'meta' => [
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        // Facebook Login for Business: the configuration (permissions) is chosen by config_id instead of scope.
        'config_id' => env('META_CONFIG_ID'),
        // Separate login configuration for Messenger + Instagram DM (Page & IG permissions).
        'chat_config_id' => env('META_CHAT_CONFIG_ID'),
        // Login configuration with WhatsApp Business permissions (connect WhatsApp without pasting a token).
        'wa_config_id' => env('META_WA_CONFIG_ID'),
        'graph_version' => env('META_GRAPH_VERSION', 'v27.0'),
        'redirect' => env('META_REDIRECT_URL') ?: rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/meta/callback',
    ],

    'binderbyte' => ['key' => env('BINDERBYTE_API_KEY')],

    // Node.js for the scheduled AWB post-processing (scripts/awb). Empty = look in PATH, /usr/local/bin, /usr/bin.
    'node' => [
        'binary' => env('NODE_BINARY'),
    ],

    'shopee' => [
        // Shopee Open Platform v2. Sandbox keys come from open.shopee.com → App → Test credentials.
        'partner_id' => (int) env('SHOPEE_PARTNER_ID', 0),
        'partner_key' => env('SHOPEE_PARTNER_KEY'),
        'host' => env('SHOPEE_ENV', 'sandbox') === 'live'
            ? 'https://partner.shopeemobile.com'
            : 'https://openplatform.sandbox.test-stable.shopee.sg', // Sandbox v2 (console → API Test Tool)
        // Empty .env values fall back to the defaults.
        'redirect' => env('SHOPEE_REDIRECT_URL') ?: rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/shopee/callback',
        'push_url' => env('SHOPEE_PUSH_URL') ?: null,
        'push_key' => env('SHOPEE_PUSH_PARTNER_KEY') ?: null, // Live Push Partner Key
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

];
