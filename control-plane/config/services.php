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
        'download_android' => env('MVPN_DOWNLOAD_ANDROID', '/storage/downloads/Mbunie-VPN-1.0.17.apk'),
        'download_windows' => env('MVPN_DOWNLOAD_WINDOWS', '/storage/downloads/Mbunie-VPN-Setup-1.0.17.exe'),
        'download_macos' => env('MVPN_DOWNLOAD_MACOS', '/storage/downloads/Mbunie-VPN-1.0.13-macos.dmg'),
        'app_version' => env('MVPN_APP_VERSION', '1.0.13'),
        // Android can ship ahead of the desktop installers.
        'android_version' => env('MVPN_ANDROID_VERSION', '1.0.17'),
        'windows_version' => env('MVPN_WINDOWS_VERSION', '1.0.17'),
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
    ],

    // MbunieEduHub (mbuniehub.com): signed partner API (EduHub sells/activates VPN plans).
    'eduhub' => [
        'url' => rtrim((string) env('EDUHUB_URL', 'https://mbuniehub.com'), '/'),
        // Shared with EduHub's MVPN_PARTNER_SECRET; signs partner API calls.
        'partner_secret' => env('EDUHUB_PARTNER_SECRET'),
        'timeout' => (int) env('EDUHUB_TIMEOUT', 15),
    ],

];
