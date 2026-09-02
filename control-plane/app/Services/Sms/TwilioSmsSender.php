<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Twilio REST API (no SDK). Works for +86 China numbers and most of the world.
 * Set TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM in .env.
 */
class TwilioSmsSender implements SmsSender
{
    public function __construct(
        private string $sid,
        private string $token,
        private string $from,
    ) {}

    public function send(string $to, string $message): void
    {
        $resp = Http::asForm()
            ->withBasicAuth($this->sid, $this->token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                'To' => $to,
                'From' => $this->from,
                'Body' => $message,
            ]);

        if ($resp->failed()) {
            throw new RuntimeException('Twilio send failed: ' . $resp->body());
        }
    }
}
