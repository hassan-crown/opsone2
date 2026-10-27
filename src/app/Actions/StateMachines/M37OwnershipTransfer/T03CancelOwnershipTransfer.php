<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\TransitionRecorder;
use App\Enums\OwnershipTransferStatus;
use App\Models\OwnershipTransfer;
use App\Models\User;

/**
 * M-37-T03: the requesting owner cancels before a decision.
 */
final class T03CancelOwnershipTransfer extends OwnershipTransferTransition
{
    public function handle(User $actor, OwnershipTransfer $transfer, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): OwnershipTransfer
    {
        return $this->decide('T03', $transfer, OwnershipTransferStatus::Cancelled, $actor,
            fn (OwnershipTransfer $t): ?string => $this->isMember($actor, $t->from_membership_id) ? null : 'not_requester',
            null, $source, $reason,
        );
    }
}
