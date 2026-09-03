<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

/**
 * Applies admin-managed settings over config() at boot, so every consumer that
 * reads config('services.stripe.secret') etc. transparently picks up values
 * entered in the Filament admin panel. .env stays the fallback.
 */
class SettingsServiceProvider extends ServiceProvider
{
    /** setting key => config path(s) */
    private const MAP = [
        // --- email ---
        'mail_driver' => 'mail.default',
        'mail_host' => 'mail.mailers.smtp.host',
        'mail_port' => 'mail.mailers.smtp.port',
        'mail_username' => 'mail.mailers.smtp.username',
        'mail_password' => 'mail.mailers.smtp.password',
        'mail_from_address' => ['mail.from.address'],
        'mail_from_name' => ['mail.from.name'],
        'brevo_api_key' => 'services.brevo.key',
        // --- payments ---
        'stripe_key' => 'services.stripe.key',
        'stripe_secret' => 'services.stripe.secret',
        'stripe_webhook_secret' => 'services.stripe.webhook_secret',
        'cryptomus_merchant_id' => 'services.cryptomus.merchant_id',
        'cryptomus_payment_key' => 'services.cryptomus.payment_key',
        'clickpesa_client_id' => 'services.clickpesa.client_id',
        'clickpesa_api_key' => 'services.clickpesa.api_key',
        'clickpesa_checksum_secret' => 'services.clickpesa.checksum_secret',
        // --- sms ---
        'twilio_sid' => 'services.twilio.sid',
        'twilio_token' => 'services.twilio.token',
        'twilio_from' => 'services.twilio.from',
        // --- alerts ---
        'anthropic_api_key' => 'services.anthropic.key',
        'telegram_bot_token' => 'services.telegram.bot_token',
        'telegram_chat_id' => 'services.telegram.chat_id',
    ];

    public function boot(): void
    {
        $map = Setting::map();
        if ($map === []) {
            return;
        }

        foreach (self::MAP as $key => $targets) {
            $val = $map[$key] ?? null;
            if ($val === null || $val === '') {
                continue;
            }
            foreach ((array) $targets as $path) {
                config([$path => $val]);
            }
        }

        // mail_driver "smtps://" scheme convenience: port 465 → smtps
        if (($map['mail_port'] ?? null) === '465') {
            config(['mail.mailers.smtp.scheme' => 'smtps']);
        }
    }
}
