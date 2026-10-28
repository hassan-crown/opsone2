<?php

namespace App\Finance;

use App\Support\GaplessSequence;

/**
 * Receipt numbers per facility and year without gaps (Q-41): RC-2026-000123. One deployment is one facility
 * (ADR-0002); taken inside the confirming transaction, so a rollback gives the number back.
 */
final class ReceiptNumbers
{
    public function __construct(private readonly GaplessSequence $sequence) {}

    public function next(): string
    {
        return $this->sequence->format('receipt', 'RC', 6);
    }
}
