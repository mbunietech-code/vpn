<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // group, key, label, secret?
            ['email', 'mail_driver', 'Mail driver (smtp / log)', false],
            ['email', 'mail_host', 'SMTP host', false],
            ['email', 'mail_port', 'SMTP port (465 / 587)', false],
            ['email', 'mail_username', 'SMTP username', false],
            ['email', 'mail_password', 'SMTP password', true],
            ['email', 'mail_from_address', 'From address', false],
            ['email', 'mail_from_name', 'From name', false],
            ['email', 'brevo_api_key', 'Brevo API key (recommended)', true],

            ['payments', 'stripe_key', 'Stripe publishable key', false],
            ['payments', 'stripe_secret', 'Stripe secret key', true],
            ['payments', 'stripe_webhook_secret', 'Stripe webhook signing secret', true],
            ['payments', 'stripe_methods', 'Stripe payment methods, comma separated (default: alipay,card; add wechat_pay only if your Stripe country supports it)', false],
            ['payments', 'pesapal_consumer_key', 'Pesapal consumer key', false],
            ['payments', 'pesapal_consumer_secret', 'Pesapal consumer secret', true],
            ['payments', 'pesapal_currency', 'Pesapal currency your account charges in: usd (default) or tzs', false],
            ['payments', 'pesapal_sandbox', 'Test mode: 1 = Pesapal sandbox keys, empty = live', false],
            ['payments', 'cryptomus_merchant_id', 'Cryptomus merchant ID', false],
            ['payments', 'cryptomus_payment_key', 'Cryptomus payment key', true],
            ['payments', 'clickpesa_client_id', 'ClickPesa client-id', false],
            ['payments', 'clickpesa_api_key', 'ClickPesa api-key', true],
            ['payments', 'clickpesa_merchant_id', 'ClickPesa merchant ID', false],
            ['payments', 'clickpesa_checksum_secret', 'ClickPesa checksum secret (optional)', true],

            ['sms', 'twilio_sid', 'Twilio Account SID', false],
            ['sms', 'twilio_token', 'Twilio Auth Token', true],
            ['sms', 'twilio_from', 'Twilio sender number', false],

            ['alerts', 'anthropic_api_key', 'Anthropic API key (AI alerts)', true],
            ['alerts', 'telegram_bot_token', 'Telegram bot token', true],
            ['alerts', 'telegram_chat_id', 'Telegram chat ID', false],
            ['alerts', 'payment_alert_emails', 'Emails for "payment to review" alerts, comma separated (empty = all VPN admins)', false],

            ['app', 'android_download_url', 'Android engine APK / release URL', false],
            ['app', 'vip_android_link', 'VIP app for Android (full https:// link or /storage/downloads/file name; type off to hide the button)', false],
            ['app', 'vip_windows_link', 'VIP app for Windows (full https:// link or /storage/downloads/file name; type off to hide the button)', false],
        ];

        foreach ($rows as $i => [$group, $key, $label, $secret]) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['label' => $label, 'group' => $group, 'is_secret' => $secret, 'sort' => $i],
            );
        }
    }
}
