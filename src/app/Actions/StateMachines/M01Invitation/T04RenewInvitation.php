<?php

namespace App\Actions\StateMachines\M01Invitation;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\InvitationStatus;
use App\Enums\StateMachine;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-01-T04: renew a pending or expired invitation (P-05). The old one becomes cancelled and its link can never
 * be reused; a new pending invitation with the same placement and roles is created.
 */
final class T04RenewInvitation
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly T00CreateInvitation $create,
    ) {}

    /**
     * @return array{0: Invitation, 1: string}
     */
    public function handle(User $actor, Invitation $invitation, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        $this->access->authorize($actor, Permission::P05, $invitation);

        return DB::transaction(function () use ($actor, $invitation, $source) {
            $old = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $from = $old->status;

            if ($from !== InvitationStatus::Pending && $from !== InvitationStatus::Expired) {
                throw new InvalidTransition(StateMachine::M01, 'T04', 'not_renewable');
            }

            // Release the old one first so the "one pending invitation per email" rule holds.
            $old->forceFill(['status' => InvitationStatus::Cancelled])->save();

            [$new, $token] = $this->create->handle($actor, [
                'email' => $old->email,
                'name' => $old->name,
                'job_title' => $old->job_title,
                'department_id' => $old->department_id,
                'branch_ids' => $old->accessBranchIds(),
                'roles' => $old->roles,
            ], $source, 'T04');

            $old->forceFill(['replaced_by_id' => $new->id])->save();
            $this->transitions->record(
                StateMachine::M01, 'T04', $old, $from, InvitationStatus::Cancelled, $actor, $source, null, ['replaced_by' => $new->id],
            );

            return [$new, $token];
        });
    }
}
