<?php

namespace App\Money;

/**
 * One line's amount split into net, VAT and gross (gross = net + vat, always exactly).
 */
final readonly class VatBreakdown
{
    public function __construct(public Money $net, public Money $vat, public Money $gross) {}

    /**
     * @return array{net_minor: int, vat_minor: int, gross_minor: int}
     */
    public function toArray(): array
    {
        return ['net_minor' => $this->net->minor, 'vat_minor' => $this->vat->minor, 'gross_minor' => $this->gross->minor];
    }
}
