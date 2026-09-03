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
        ];

        foreach ($rows as $i => [$group, $key, $label, $secret]) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['label' => $label, 'group' => $group, 'is_secret' => $secret, 'sort' => $i],
            );
        }
    }
}
