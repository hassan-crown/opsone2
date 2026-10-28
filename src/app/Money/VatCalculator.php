<?php

namespace App\Money;

use InvalidArgumentException;

/**
 * VAT per line (Q-35 a): the facility's rate in basis points (1500 = 15%) and whether entered prices include it.
 * Inclusive: vat = amount × r / (1 + r), rounded half-up, net = amount − vat. Exclusive: vat = amount × r, rounded
 * half-up, gross = amount + vat. Totals are sums of rounded lines, never a rounding of the total. The tax invoice
 * itself is issued outside OpsOne (BR-20).
 */
final class VatCalculator
{
    public const MAX_RATE_BP = 5_000;

    public function __construct(private readonly int $rateBp, private readonly bool $inclusive)
    {
        if ($rateBp < 0 || $rateBp > self::MAX_RATE_BP) {
            throw new InvalidArgumentException('VAT rate out of range.');
        }
    }

    public function line(Money $amount): VatBreakdown
    {
        if ($this->inclusive) {
            $vat = $amount->ratio($this->rateBp, Money::BP + $this->rateBp);

            return new VatBreakdown($amount->minus($vat), $vat, $amount);
        }

        $vat = $amount->percent($this->rateBp);

        return new VatBreakdown($amount, $vat, $amount->plus($vat));
    }

    public function rateBp(): int
    {
        return $this->rateBp;
    }

    public function inclusive(): bool
    {
        return $this->inclusive;
    }
}
