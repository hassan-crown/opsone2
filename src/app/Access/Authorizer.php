<?php

namespace App\Access;

use App\Access\Contracts\LicenseCapabilities;
use App\Access\Contracts\LicensedRecord;
use App\Access\Contracts\StatefulRecord;
use App\Access\Enums\Audience;
use App\Access\Enums\DenyReason;
use App\Access\Enums\LicenseMode;
use App\Enums\MembershipStatus;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\User;
use App\SupportAccess\SupportAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The central Gate. Deny by default; evaluation order:
 * license (capability — of the permission, then of the record — then phase) → active account → scoped grant → record state.
 *
 * Effective grants are memoized per request and keyed by the user's `access_version`, which is read fresh
 * on every check, so a grant change or disable takes effect immediately even inside the same request.
 */
final class Authorizer
{
    /**
     * @var array<string, array{active: bool, member: bool, grants: list<Grant>}>
     */
    private array $memo = [];

    public function __construct(
        private readonly LicenseCapabilities $license,
        private readonly GrantResolver $resolver,
        private readonly ScopeResolver $scopes,
        private readonly SupportAccess $support,
    ) {}

    public function can(?Authenticatable $user, Permission|string $permission, ?object $record = null): bool
    {
        return $this->inspect($user, $permission, $record)->allowed;
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(?Authenticatable $user, Permission|string $permission, ?object $record = null): void
    {
        $this->inspect($user, $permission, $record)->toResponse()->authorize();
    }

    public function inspect(?Authenticatable $user, Permission|string $permission, ?object $record = null): AccessDecision
    {
        $permission = $permission instanceof Permission ? $permission : Permission::tryFrom($permission);

        if ($permission === null) {
            return AccessDecision::deny(DenyReason::UnknownPermission);
        }

        $audience = $permission->audience();
        if (! $user instanceof User || ($audience !== Audience::Staff && $audience !== Audience::Self)) {
            return AccessDecision::deny(DenyReason::Audience);
        }

        $capability = $permission->capability();
        if ($capability !== null && ! $this->license->allows($capability)) {
            return AccessDecision::deny(DenyReason::License);
        }

        $recordCapability = $record instanceof LicensedRecord ? $record->requiredCapability($permission) : null;
        if ($recordCapability !== null && ! $this->license->allows($recordCapability)) {
            return AccessDecision::deny(DenyReason::License);
        }

        // The user's own account (sign-in, password, sessions, notifications) stays open in every license phase.
        if ($audience === Audience::Staff) {
            $mode = $this->license->mode();
            if ($mode === LicenseMode::ReadOnly && ! $permission->availableReadOnly()) {
                return AccessDecision::deny(DenyReason::LicenseReadOnly);
            }
            if ($mode === LicenseMode::NoNewWork && $permission->startsNewWork()) {
                return AccessDecision::deny(DenyReason::LicenseNoNewWork);
            }
        }

        $state = $this->stateFor($user);
        if (! $state['active'] || ($audience === Audience::Staff && ! $state['member'])) {
            return AccessDecision::deny(DenyReason::InactiveAccount);
        }

        $grants = $audience === Audience::Self
            ? [new Grant($permission, Enums\Scope::Own)]
            : array_values(array_filter($state['grants'], fn (Grant $g) => $g->permission === $permission));

        if ($grants === []) {
            return AccessDecision::deny(DenyReason::NoGrant);
        }

        $covered = false;
        foreach ($grants as $grant) {
            if ($this->scopes->covers($grant, $user, $record)) {
                $covered = true;
                break;
            }
        }

        if (! $covered) {
            return AccessDecision::deny(DenyReason::OutOfScope);
        }

        if ($record instanceof StatefulRecord && ! $record->permitsAction($permission)) {
            return AccessDecision::deny(DenyReason::RecordState);
        }

        return AccessDecision::allow();
    }

    /**
     * Effective grants of a user for one permission, for building scoped queries (lists, search, export).
     *
     * @return list<Grant>
     */
    public function grantsFor(User $user, Permission $permission): array
    {
        if ($this->inspect($user, $permission)->allowed === false) {
            return [];
        }

        return array_values(array_filter($this->stateFor($user)['grants'], fn (Grant $g) => $g->permission === $permission));
    }

    /**
     * @return list<Grant>
     */
    public function effectiveGrants(User $user): array
    {
        $state = $this->stateFor($user);

        return $state['active'] ? $state['grants'] : [];
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{active: bool, member: bool, grants: list<Grant>}
     */
    private function stateFor(User $user): array
    {
        $version = User::query()->whereKey($user->id)->value('access_version');
        if ($version === null) {
            return ['active' => false, 'member' => false, 'grants' => []];
        }

        $key = $user->id.':'.$version;

        return $this->memo[$key] ??= $this->loadState($user);
    }

    /**
     * @return array{active: bool, member: bool, grants: list<Grant>}
     */
    private function loadState(User $user): array
    {
        // OpsOne support (M-38): only what live grants open; any membership row is ignored, and with no live
        // grant the account is inactive. The memo key changes when a grant ends (SessionRevoker bumps the version).
        if ($user->is_support) {
            $grants = $this->support->grantsFor($user);

            return ['active' => $grants !== [], 'member' => true, 'grants' => $grants];
        }

        $membership = Membership::query()->where('user_id', $user->id)->first();

        // An account without a staff membership may only act on itself (P-64, P-84).
        if ($membership === null) {
            return ['active' => true, 'member' => false, 'grants' => []];
        }

        if ($membership->status !== MembershipStatus::Active) {
            return ['active' => false, 'member' => true, 'grants' => []];
        }

        $isOwner = Facility::current()?->owner_membership_id === $membership->id;

        return ['active' => true, 'member' => true, 'grants' => $this->resolver->forMembership($membership, $isOwner)];
    }
}
