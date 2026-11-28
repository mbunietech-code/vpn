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

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Methods offered on Stripe Checkout, in order. WeChat Pay only works on
        // Stripe accounts in some countries (not Malaysia) - add it only if the
        // Dashboard shows it as available, or Checkout refuses the whole session.
        'methods' => env('STRIPE_METHODS', 'alipay,card'),
    ],

    'cryptomus' => [
        'merchant_id' => env('CRYPTOMUS_MERCHANT_ID'),
        'payment_key' => env('CRYPTOMUS_PAYMENT_KEY'),
        'webhook_key' => env('CRYPTOMUS_WEBHOOK_KEY'),
    ],

    'clickpesa' => [
        'client_id' => env('CLICKPESA_CLIENT_ID'),
        'api_key' => env('CLICKPESA_API_KEY'),
        'merchant_id' => env('CLICKPESA_MERCHANT_ID'),
        'checksum_secret' => env('CLICKPESA_CHECKSUM_SECRET'),
    ],

    // Pesapal API 3.0 (Visa / Mastercard). Sandbox keys: developer.pesapal.com.
    'pesapal' => [
        'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
        'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
        'sandbox' => (bool) env('PESAPAL_SANDBOX', false),
        // Currency the merchant account charges in (usd | tzs | kes ...).
        'currency' => env('PESAPAL_CURRENCY', 'usd'),
        // Optional: an IPN id registered by hand; else registered automatically once.
        'ipn_id' => env('PESAPAL_IPN_ID'),
        // Receipt address when the buyer gave neither email nor phone.
        'receipt_email' => env('PESAPAL_RECEIPT_EMAIL', 'support@mbuniehub.com'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'alert_model' => env('MVPN_ALERT_MODEL', 'claude-sonnet-5'),
    ],

    'telegram' => [
        'bot_token' => env('MVPN_ALERT_TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('MVPN_ALERT_TELEGRAM_CHAT_ID'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
    ],

    'mvpn' => [
        'currencies' => explode(',', (string) env('MVPN_CURRENCIES', 'usd,cny')),
        'default_currency' => env('MVPN_DEFAULT_CURRENCY', 'usd'),
        'provision_timeout' => (int) env('MVPN_PROVISION_TIMEOUT', 60),
        // Public installers shown on /download (files under storage/app/public/downloads).
        'download_android' => env('MVPN_DOWNLOAD_ANDROID', '/storage/downloads/Mbunie-VPN-1.0.26.apk'),
        'download_windows' => env('MVPN_DOWNLOAD_WINDOWS', '/storage/downloads/Mbunie-VPN-Setup-1.0.26.exe'),
        'download_macos' => env('MVPN_DOWNLOAD_MACOS', '/storage/downloads/Mbunie-VPN-1.0.26-macos.dmg'),
        'app_version' => env('MVPN_APP_VERSION', '1.0.26'),
        // Android can ship ahead of the desktop installers.
        'android_version' => env('MVPN_ANDROID_VERSION', '1.0.26'),
        'windows_version' => env('MVPN_WINDOWS_VERSION', '1.0.26'),
        // The VIP event app (code sign-in only), offered on VIP pass pages.
        // A platform left empty has no VIP installer and shows no button.
        'download_vip_android' => env('MVPN_DOWNLOAD_VIP_ANDROID', '/storage/downloads/Mbunie-VIP-1.0.26.apk'),
        'download_vip_windows' => env('MVPN_DOWNLOAD_VIP_WINDOWS', '/storage/downloads/Mbunie-VIP-Setup-1.0.26.exe'),
        // Apps older than this must update before they can be used (null = never forced).
        'min_app_version' => env('MVPN_MIN_APP_VERSION'),
        // Units of each currency per 1 USD, for rough revenue totals on the dashboard.
        'usd_rate' => [
            'usd' => 1,
            'cny' => (float) env('MVPN_CNY_PER_USD', 7.1),
            'tzs' => (float) env('MVPN_TZS_PER_USD', 2640),
        ],
        // Who gets "payment to review" emails (comma separated). Empty = every VPN admin.
        'payment_alert_emails' => env('MVPN_PAYMENT_ALERT_EMAILS'),
        // Node capacity alerts (Admin > Alerts + email to the alert recipients).
        'quota_alert_percent' => (int) env('MVPN_QUOTA_ALERT_PERCENT', 80),
        'subscriber_alert' => (int) env('MVPN_SUBSCRIBER_ALERT', 80),
        // Our own services the tunnel must never carry (comma separated host names).
        // Live-class video is real-time UDP: inside the tunnel (often TCP) it freezes.
        // Their addresses are looked up when a config is built.
        'direct_hosts' => array_values(array_filter(array_map('trim', explode(',',
            (string) env('MVPN_DIRECT_HOSTS', 'live.mbuniehub.com,turn.mbuniehub.com'))))),
        // Hysteria2 "Brutal": the app tells the server its real speed, so the
        // server keeps sending at that rate when China's lines drop packets
        // (plain BBR backs off and stalls). Moderate on purpose: set it far
        // above real speed and it only adds loss. 0 = off (BBR).
        'hysteria_up_mbps' => (int) env('MVPN_HYSTERIA_UP_MBPS', 20),
        'hysteria_down_mbps' => (int) env('MVPN_HYSTERIA_DOWN_MBPS', 50),
        // How often the app re-tests servers and moves off a failing one.
        'urltest_interval' => env('MVPN_URLTEST_INTERVAL', '1m'),
    ],

    // MbunieEduHub (mbuniehub.com): signed partner API (EduHub sells/activates VPN plans).
    'eduhub' => [
        'url' => rtrim((string) env('EDUHUB_URL', 'https://mbuniehub.com'), '/'),
        // Shared with EduHub's MVPN_PARTNER_SECRET; signs partner API calls.
        'partner_secret' => env('EDUHUB_PARTNER_SECRET'),
        'timeout' => (int) env('EDUHUB_TIMEOUT', 15),
    ],

];
