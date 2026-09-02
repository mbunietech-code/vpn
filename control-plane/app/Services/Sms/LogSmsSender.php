<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Fallback driver — records the message instead of sending it. */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::channel('stack')->info("SMS to {$to}: {$message}");
    }
}
