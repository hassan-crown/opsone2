<?php

namespace App\Customers\Rules;

use App\Customers\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number that normalizes to E.164. `mobileOnly` limits Saudi numbers to mobile ranges (5XXXXXXXX).
 */
final class PhoneNumber implements ValidationRule
{
    public function __construct(private readonly bool $mobileOnly = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Phone::normalize($value, $this->mobileOnly) === null) {
            $fail('customers.validation.phone')->translate();
        }
    }
}
