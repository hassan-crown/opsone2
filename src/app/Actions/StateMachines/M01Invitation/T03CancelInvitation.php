<?php

namespace App\Actions\StateMachines\M01Invitation;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\StateMachine;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-01-T03: cancel a pending invitation (P-05). A concurrent acceptance is re-checked under lock.
 */
final class T03CancelInvitation
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Invitation $invitation, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): Invitation
    {
        $this->access->authorize($actor, Permission::P05, $invitation);

        return DB::transaction(function () use ($actor, $invitation, $reason, $source) {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->status !== InvitationStatus::Pending) {
                throw new InvalidTransition(StateMachine::M01, 'T03', 'not_pending');
            }

            $locked->forceFill(['status' => InvitationStatus::Cancelled])->save();
            $this->transitions->record(
                StateMachine::M01, 'T03', $locked, InvitationStatus::Pending, InvitationStatus::Cancelled, $actor, $source, $reason,
            );
            Audit::record(
                AuditAction::InvitationCancelled, $locked, $actor,
                before: ['status' => InvitationStatus::Pending], after: ['status' => InvitationStatus::Cancelled],
                reason: $reason, source: $source, label: $locked->auditLabel(),
            );

            return $locked;
        });
    }
}
