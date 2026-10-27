<?php

namespace App\Actions\StateMachines\M22Membership;

use App\Access\Authorizer;
use App\Access\DelegationGuard;
use App\Access\Enums\Scope;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Grant;
use App\Access\GrantResolver;
use App\Access\Permission;
use App\Access\SessionRevoker;
use App\Access\TransitionRecorder;
use App\Enums\MembershipStatus;
use App\Enums\StateMachine;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * M-22-T03: replace a member's role assignments and grant overrides (P-04).
 * The granter may only add grants they hold within their own scope; the owner's access is not editable
 * (ownership moves only through M-37). Existing sessions of the member are revoked immediately.
 */
final class T03UpdateMemberAccess
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly GrantResolver $resolver,
        private readonly DelegationGuard $delegation,
        private readonly TransitionRecorder $transitions,
        private readonly SessionRevoker $sessions,
    ) {}

    /**
     * @param  list<array{role_template_id: int, scope?: string|null, targets?: list<int>|null}>  $roles
     * @param  list<array{permission: string, effect: string, scope?: string|null, targets?: list<int>|null}>  $overrides
     */
    public function handle(User $actor, Membership $membership, array $roles, array $overrides = [], ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): Membership
    {
        $this->access->authorize($actor, Permission::P04, $membership);

        return DB::transaction(function () use ($actor, $membership, $roles, $overrides, $reason, $source) {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);

            if ($locked->status !== MembershipStatus::Active) {
                throw new InvalidTransition(StateMachine::M22, 'T03', 'not_active');
            }
            if (Facility::current()?->owner_membership_id === $locked->id) {
                throw new InvalidTransition(StateMachine::M22, 'T03', 'owner');
            }

            try {
                $next = $this->resolver->resolvePayload($locked->department_id, $locked->branchIds(), $roles, $overrides);
            } catch (InvalidArgumentException) {
                throw new InvalidTransition(StateMachine::M22, 'T03', 'invalid_grant');
            }

            $current = array_map(fn (Grant $g) => $g->key(), $this->resolver->forMembership($locked, false));
            $added = array_values(array_filter($next, fn (Grant $g) => ! in_array($g->key(), $current, true)));
            $this->delegation->assertCanGrant($actor, $added);

            $locked->roles()->delete();
            foreach ($roles as $role) {
                $locked->roles()->create([
                    'role_template_id' => $role['role_template_id'],
                    'scope' => isset($role['scope']) ? Scope::from($role['scope']) : null,
                    'targets' => $role['targets'] ?? null,
                ]);
            }

            $grantedBy = Membership::query()->where('user_id', $actor->id)->value('id');
            $locked->grants()->delete();
            foreach ($overrides as $override) {
                $locked->grants()->create([
                    'permission' => $override['permission'],
                    'effect' => $override['effect'],
                    'scope' => $override['scope'] ?? null,
                    'targets' => $override['targets'] ?? null,
                    'granted_by_membership_id' => $grantedBy,
                ]);
            }
            $locked->touch();

            $this->sessions->revoke($locked->user_id);
            $this->transitions->record(
                StateMachine::M22, 'T03', $locked, MembershipStatus::Active, MembershipStatus::Active, $actor, $source, $reason,
                ['added' => array_map(fn (Grant $g) => $g->key(), $added)],
            );

            return $locked->refresh();
        });
    }
}
