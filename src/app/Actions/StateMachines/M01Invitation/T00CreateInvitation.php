<?php

namespace App\Actions\StateMachines\M01Invitation;

use App\Access\Authorizer;
use App\Access\DelegationGuard;
use App\Access\Exceptions\InvalidTransition;
use App\Access\GrantResolver;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\StateMachine;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * M-01-T00 (implicit creation): invite a staff member (P-05) with roles and placement.
 * Returns the plain link token once; only its hash is stored. Valid for seven days.
 * A pending invitation reserves one seat against the license limit (LicenseLimits).
 */
final class T00CreateInvitation
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly GrantResolver $resolver,
        private readonly DelegationGuard $delegation,
        private readonly TransitionRecorder $transitions,
        private readonly LicenseLimits $limits,
    ) {}

    /**
     * @param  array{email: string, name?: string|null, job_title?: string|null, department_id?: int|null, branch_ids?: list<int>, roles: list<array{role_template_id: int, scope?: string|null, targets?: list<int>|null}>}  $data
     * @return array{0: Invitation, 1: string}
     */
    public function handle(User $actor, array $data, string $source = TransitionRecorder::SOURCE_WEB, ?string $transition = 'T00'): array
    {
        $email = Str::lower(trim($data['email']));
        $invitation = new Invitation([
            'email' => $email,
            'name' => $data['name'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'branch_ids' => $data['branch_ids'] ?? [],
            'roles' => $data['roles'],
        ]);

        $this->access->authorize($actor, Permission::P05, $invitation);

        try {
            $grants = $this->resolver->resolvePayload($invitation->department_id, $invitation->accessBranchIds(), $data['roles']);
        } catch (InvalidArgumentException) {
            throw new InvalidTransition(StateMachine::M01, 'T00', 'invalid_grant');
        }
        $this->delegation->assertCanGrant($actor, $grants);

        return DB::transaction(function () use ($actor, $invitation, $email, $source, $transition) {
            if (User::query()->where('email', $email)->whereHas('membership')->exists()) {
                throw new InvalidTransition(StateMachine::M01, 'T00', 'already_member');
            }
            if (Invitation::query()->where('email', $email)->where('status', InvitationStatus::Pending->value)
                ->where('expires_at', '>', now())->lockForUpdate()->exists()) {
                throw new InvalidTransition(StateMachine::M01, 'T00', 'pending_exists');
            }
            $this->limits->reserveSeat();

            $token = Str::random(64);
            $invitation->fill([
                'token_hash' => Invitation::hashToken($token),
                'invited_by_membership_id' => Membership::query()->where('user_id', $actor->id)->value('id'),
                'expires_at' => now()->addDays(Invitation::VALID_DAYS),
            ])->save();

            if ($transition !== null) {
                $this->transitions->record(StateMachine::M01, $transition, $invitation, null, InvitationStatus::Pending, $actor, $source);
            }
            if ($transition === 'T00') {
                Audit::record(AuditAction::InvitationCreated, $invitation, $actor, after: $invitation->auditFacts(), source: $source, label: $invitation->auditLabel());
            }

            return [$invitation, $token];
        });
    }
}
