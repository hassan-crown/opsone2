<?php

namespace App\Licensing;

use RuntimeException;

final class LicenseVerificationException extends RuntimeException
{
    public function __construct(public readonly LicenseProblem $problem, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'License rejected: '.$problem->value);
    }
}
