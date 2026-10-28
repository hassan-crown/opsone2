<?php

namespace App\Portal;

use RuntimeException;

/**
 * Too many codes asked or wrong codes typed for this identifier or address. The same answer whether the account exists.
 */
final class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('otp_throttled');
    }
}
