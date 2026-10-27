<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\TransitionRecorder;
use App\Enums\OwnershipTransferStatus;
use App\Models\OwnershipTransfer;
use App\Models\User;

/**
 * M-37-T02: the recipient rejects the request.
 */
final class T02RejectOwnershipTransfer extends OwnershipTransferTransition
{
    public function handle(User $actor, OwnershipTransfer $transfer, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): OwnershipTransfer
    {
        return $this->decide('T02', $transfer, OwnershipTransferStatus::Rejected, $actor,
            fn (OwnershipTransfer $t): ?string => $this->isMember($actor, $t->to_membership_id) ? null : 'not_recipient',
            null, $source, $reason,
        );
    }
}
