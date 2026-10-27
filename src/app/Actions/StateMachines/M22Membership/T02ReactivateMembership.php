<?php

namespace App\Actions\StateMachines\M22Membership;

use App\Access\Authorizer;
use App\Access\Exceptions\InvalidTransition;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M-22-T02: reactivate a disabled member (P-06). The member takes a seat again, so the license limit applies.
 */
final class T02ReactivateMembership
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly LicenseLimits $limits,
    ) {}

    public function handle(User $actor, Membership $membership, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): Membership
    {
        $this->access->authorize($actor, Permission::P06, $membership);

        return DB::transaction(function () use ($actor, $membership, $reason, $source) {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);

            if ($locked->status !== MembershipStatus::Disabled) {
                throw new InvalidTransition(StateMachine::M22, 'T02', 'not_disabled');
            }
            $this->limits->reserveSeat();

            $locked->forceFill([
                'status' => MembershipStatus::Active,
                'disabled_at' => null,
                'disabled_reason' => null,
            ])->save();

            $this->transitions->record(
                StateMachine::M22, 'T02', $locked, MembershipStatus::Disabled, MembershipStatus::Active, $actor, $source, $reason,
            );
            Audit::record(
                AuditAction::MembershipReactivated, $locked, $actor,
                before: ['status' => MembershipStatus::Disabled], after: ['status' => MembershipStatus::Active],
                reason: $reason, source: $source,
            );

            return $locked;
        });
    }
}
