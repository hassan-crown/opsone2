<?php

namespace App\Customers\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Saudi VAT registration number: 15 digits, starting and ending with 3. Checked only when filled (COM-01).
 */
final class SaudiVatNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^3\d{13}3$/', $value) !== 1) {
            $fail('customers.validation.vat')->translate();
        }
    }
}
