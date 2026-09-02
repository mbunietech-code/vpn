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
    ],

    'cryptomus' => [
        'merchant_id' => env('CRYPTOMUS_MERCHANT_ID'),
        'payment_key' => env('CRYPTOMUS_PAYMENT_KEY'),
        'webhook_key' => env('CRYPTOMUS_WEBHOOK_KEY'),
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

    'mvpn' => [
        'currencies' => explode(',', (string) env('MVPN_CURRENCIES', 'usd,cny')),
        'default_currency' => env('MVPN_DEFAULT_CURRENCY', 'usd'),
        'provision_timeout' => (int) env('MVPN_PROVISION_TIMEOUT', 60),
    ],

];
