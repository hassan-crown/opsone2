<?php

namespace App\Finance\Settlement;

use App\Support\GaplessSequence;

/**
 * Gapless voucher numbers per facility and year (Q-41), taken inside the transaction that issues them so a rollback
 * gives the number back: refund vouchers RF-2026-000123 and correction vouchers CR-2026-000123.
 */
final class VoucherNumbers
{
    public function __construct(private readonly GaplessSequence $sequence) {}

    public function refund(): string
    {
        return $this->sequence->format('refund', 'RF', 6);
    }

    public function correction(): string
    {
        return $this->sequence->format('correction', 'CR', 6);
    }
}
