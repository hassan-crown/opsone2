<?php

namespace App\Money;

use InvalidArgumentException;

/**
 * An amount outside what OpsOne stores (±MinorUnits::MAX halalas). Raised before anything is saved; an amount is
 * never stored saturated or wrapped.
 */
final class MoneyOverflow extends InvalidArgumentException
{
    public static function of(string $value): self
    {
        return new self("Amount {$value} is outside the supported range.");
    }
}
