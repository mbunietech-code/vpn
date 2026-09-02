<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers an OTP code over the right channel.
 * Email works today (SMTP). SMS uses Twilio when configured, otherwise logs.
 */
class OtpDelivery
{
    public function send(string $identifier, string $channel, string $code): void
    {
        if ($channel === 'email') {
            Mail::to($identifier)->send(new OtpMail($code));
            return;
        }

        $this->smsSender()->send($identifier, "Mbunie VPN code: {$code} (dakika 10)");
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
