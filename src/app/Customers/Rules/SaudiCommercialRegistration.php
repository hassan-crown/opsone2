<?php

namespace App\Customers\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Saudi commercial registration number: 10 digits. Checked only when filled (COM-01).
 */
final class SaudiCommercialRegistration implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{10}$/', $value) !== 1) {
            $fail('customers.validation.cr')->translate();
        }
    }
}
