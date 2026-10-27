<?php

namespace App\Actions\StateMachines\M37OwnershipTransfer;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MembershipStatus;
use App\Enums\OwnershipTransferStatus;
use App\Enums\StateMachine;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * M-37-T00 (proposed, Q-18): the current owner re-verifies their password and requests to transfer ownership
 * (P-07) to an active member. One open request at a time; it expires after seven days.
 */
final class T00RequestOwnershipTransfer
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly Notifier $notifier,
    ) {}

    public function handle(User $actor, string $currentPassword, Membership $recipient, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): OwnershipTransfer
    {
        $this->access->authorize($actor, Permission::P07);

        if (! Hash::check($currentPassword, $actor->password)) {
            throw new AuthorizationException(__('auth.password'));
        }

        return DB::transaction(function () use ($actor, $recipient, $reason, $source) {
            $facility = Facility::query()->lockForUpdate()->firstOrFail();
            $owner = Membership::query()->where('user_id', $actor->id)->first();

            if ($owner === null || $facility->owner_membership_id !== $owner->id) {
                throw new InvalidTransition(StateMachine::M37, 'T00', 'not_owner');
            }
            $recipient = Membership::query()->lockForUpdate()->findOrFail($recipient->id);
            if ($recipient->id === $owner->id || $recipient->status !== MembershipStatus::Active) {
                throw new InvalidTransition(StateMachine::M37, 'T00', 'recipient_not_eligible');
            }
            if (OwnershipTransfer::query()->where('status', OwnershipTransferStatus::Requested->value)->where('expires_at', '>', now())->exists()) {
                throw new InvalidTransition(StateMachine::M37, 'T00', 'open_request_exists');
            }

            $transfer = OwnershipTransfer::query()->create([
                'from_membership_id' => $owner->id,
                'to_membership_id' => $recipient->id,
                'reason' => $reason,
                'expires_at' => now()->addDays(OwnershipTransfer::VALID_DAYS),
            ]);

            $this->transitions->record(StateMachine::M37, 'T00', $transfer, null, OwnershipTransferStatus::Requested, $actor, $source, $reason);
            Audit::record(AuditAction::OwnershipTransferRequested, $transfer, $actor,
                after: ['from_membership_id' => $owner->id, 'to_membership_id' => $recipient->id, 'expires_at' => $transfer->expires_at],
                reason: $reason, source: $source,
            );

            // In-app and email notice for the recipient; both commit with the request and are sent after commit.
            $this->notifier->send($recipient->user, 'ownership.transfer_requested', ['from' => $actor->name], null, 'ownership-transfer:'.$transfer->id);

            return $transfer;
        });
    }
}
