<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers an OTP code over the right channel.
 *   email → Brevo HTTP API when BREVO_API_KEY is set (reliable on shared
 *           hosting where SMTP auth / proc_open are blocked), else SMTP.
 *   sms   → Twilio when configured, otherwise logged.
 */
class OtpDelivery
{
    public function send(string $identifier, string $channel, string $code): void
    {
        if ($channel === 'email') {
            $this->email($identifier, $code);
            return;
        }

        $this->smsSender()->send($identifier, "Mbunie VPN code: {$code} (dakika 10)");
    }

    private function email(string $to, string $code): void
    {
        $brevoKey = config('services.brevo.key');

        if ($brevoKey) {
            $html = (new OtpMail($code))->render();
            $resp = Http::withHeaders(['api-key' => $brevoKey, 'accept' => 'application/json'])
                ->post('https://api.brevo.com/v3/smtp/email', [
                    'sender' => [
                        'email' => config('mail.from.address'),
                        'name' => config('mail.from.name', 'Mbunie VPN'),
                    ],
                    'to' => [['email' => $to]],
                    'subject' => "Mbunie VPN — your login code: {$code}",
                    'htmlContent' => $html,
                ]);

            if ($resp->failed()) {
                throw new \RuntimeException('Brevo send failed: ' . $resp->body());
            }
            return;
        }

        Mail::to($to)->send(new OtpMail($code));
    }

    private function smsSender(): SmsSender
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if ($sid && $token && $from) {
            return new TwilioSmsSender($sid, $token, $from);
        }

        Log::warning('OtpDelivery: Twilio not configured, SMS will only be logged.');

        return new LogSmsSender;
    }
}
