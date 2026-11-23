<?php

namespace App\Services;

use RuntimeException;

/** A VIP code that can't sign this device in, with the reason for the app. */
class VipClaimException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 403)
    {
        parent::__construct($message);
    }
}
