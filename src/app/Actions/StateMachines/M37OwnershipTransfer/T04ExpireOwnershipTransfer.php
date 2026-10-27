<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Enums\OwnershipTransferStatus;
use App\Models\OwnershipTransfer;

/**
 * M-37-T04: the request lapses after seven days without a decision (time service).
 */
final class T04ExpireOwnershipTransfer extends OwnershipTransferTransition
{
    public function handle(OwnershipTransfer $transfer): OwnershipTransfer
    {
        return $this->decide('T04', $transfer, OwnershipTransferStatus::Expired, null,
            fn (OwnershipTransfer $t): ?string => $t->expires_at->lessThanOrEqualTo(now()) ? null : 'not_due',
            null, TransitionRecorder::SOURCE_SYSTEM,
        );
    }

    public function handleDue(): int
    {
        $count = 0;
        $due = OwnershipTransfer::query()
            ->where('status', OwnershipTransferStatus::Requested->value)
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($due as $transfer) {
            try {
                $this->handle($transfer);
                $count++;
            } catch (InvalidTransition) {
                // Decided concurrently; nothing to do.
            }
        }

        return $count;
    }
}
