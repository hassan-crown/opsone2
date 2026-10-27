<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\SessionRevoker;
use App\Access\TransitionRecorder;
use App\Enums\MembershipStatus;
use App\Enums\OwnershipTransferStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use App\Models\User;

/**
 * M-37-T01: the recipient (still an active member) accepts; the facility owner changes atomically.
 * The previous owner keeps their membership and role assignments only. Both parties' sessions are revoked.
 */
final class T01AcceptOwnershipTransfer extends OwnershipTransferTransition
{
    public function __construct(TransitionRecorder $transitions, private readonly SessionRevoker $sessions)
    {
        parent::__construct($transitions);
    }

    public function handle(User $actor, OwnershipTransfer $transfer, string $source = TransitionRecorder::SOURCE_WEB): OwnershipTransfer
    {
        return $this->decide('T01', $transfer, OwnershipTransferStatus::Accepted, $actor,
            function (OwnershipTransfer $t) use ($actor): ?string {
                if (! $this->isMember($actor, $t->to_membership_id)) {
                    return 'not_recipient';
                }
                if ($t->expires_at->lessThanOrEqualTo(now())) {
                    return 'expired';
                }
                if (Membership::query()->whereKey($t->to_membership_id)->where('status', MembershipStatus::Active->value)->doesntExist()) {
                    return 'recipient_not_active';
                }
                if ((int) Facility::query()->lockForUpdate()->value('owner_membership_id') !== $t->from_membership_id) {
                    return 'owner_changed';
                }

                return null;
            },
            function (OwnershipTransfer $t): void {
                Facility::query()->update(['owner_membership_id' => $t->to_membership_id]);
                foreach (Membership::query()->whereIn('id', [$t->from_membership_id, $t->to_membership_id])->pluck('user_id') as $userId) {
                    $this->sessions->revoke((int) $userId);
                }
            },
            $source,
        );
    }
}
