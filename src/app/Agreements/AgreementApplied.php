<?php

namespace App\Agreements;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An agreement version became active (first approval or an applied change), dispatched after commit. P6b recomputes
 * the order's dues from OrderAgreement; listeners must be idempotent on `agreementId`.
 */
final class AgreementApplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $agreementId,
        public readonly int $version,
        public readonly ?int $previousTotalMinor,
        public readonly int $totalMinor,
        public readonly int $differenceMinor,
        public readonly int $settlementNeededMinor,
    ) {}
}
