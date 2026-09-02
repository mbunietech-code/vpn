<?php

namespace App\Services\Sms;

interface SmsSender
{
    /** Send a plain-text SMS. Throws on hard failure. */
    public function send(string $to, string $message): void;
}
