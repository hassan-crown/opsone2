<?php

namespace App\Actions\StateMachines\M22Membership;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\SessionRevoker;
use App\Access\TransitionRecorder;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-22-T01: disable a member (P-06). Access stops at once: sessions are revoked in the same transaction.
 * The owner can never be disabled (the facility is never left without an owner), and no one disables themselves.
 *
 * TODO(P5): hand open work over to an eligible member or a follow-up inbox (ORG-08) once tasks exist.
 */
final class T01DisableMembership
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly SessionRevoker $sessions,
    ) {}

    public function handle(User $actor, Membership $membership, string $reason, string $source = TransitionRecorder::SOURCE_WEB): Membership
    {
        $this->access->authorize($actor, Permission::P06, $membership);

        return DB::transaction(function () use ($actor, $membership, $reason, $source) {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);

            if ($locked->status !== MembershipStatus::Active) {
                throw new InvalidTransition(StateMachine::M22, 'T01', 'not_active');
            }
            if (Facility::current()?->owner_membership_id === $locked->id) {
                throw new InvalidTransition(StateMachine::M22, 'T01', 'owner');
            }
            if ($locked->user_id === $actor->id) {
                throw new InvalidTransition(StateMachine::M22, 'T01', 'self');
            }

            $locked->forceFill([
                'status' => MembershipStatus::Disabled,
                'disabled_at' => now(),
                'disabled_reason' => $reason,
            ])->save();

            $this->sessions->revoke($locked->user_id);
            $this->transitions->record(
                StateMachine::M22, 'T01', $locked, MembershipStatus::Active, MembershipStatus::Disabled, $actor, $source, $reason,
            );

            return $locked;
        });
    }
}
