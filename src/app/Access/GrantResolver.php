<?php

namespace App\Access;

use App\Access\Enums\Audience;
use App\Access\Enums\Scope;
use App\Enums\MemberGrantEffect;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\RoleTemplate;
use InvalidArgumentException;

/**
 * Computes a member's effective grants = role template grants (with the member's scope breadth) + member overrides.
 *
 * - The owner holds every staff permission with `tenant` scope; it cannot be reduced (matrix ر1).
 * - A role assignment's scope replaces the template scope only for organizational scopes the permission allows;
 *   `own`/`assigned` grants and facility-only permissions keep their template scope.
 * - Targets come from the assignment/override when given, otherwise from the member's department and branches
 *   (regions = regions of those branches).
 * - P-07 (ownership transfer) is held by the owner only; grants of it to anyone else are ignored.
 * - A `deny` override removes every grant of that permission coming from templates or `allow` overrides.
 */
final class GrantResolver
{
    /**
     * @return list<Grant>
     */
    public function forMembership(Membership $membership, bool $isOwner): array
    {
        if ($isOwner) {
            return $this->ownerGrants();
        }

        $membership->loadMissing(['roles.template.grants', 'grants']);

        $roles = [];
        foreach ($membership->roles as $role) {
            $roles[] = [
                'grants' => $role->template->is_owner
                    ? []
                    : array_values($role->template->grants->map(fn ($g) => [$g->permission, $g->scope])->all()),
                'scope' => $role->scope,
                'targets' => $role->targets,
            ];
        }

        $overrides = [];
        foreach ($membership->grants as $grant) {
            $overrides[] = [
                'permission' => $grant->permission,
                'effect' => $grant->effect,
                'scope' => $grant->scope,
                'targets' => $grant->targets,
            ];
        }

        return $this->resolve($membership->department_id, $membership->branchIds(), $roles, $overrides);
    }

    /**
     * Grants a not-yet-saved access payload would produce (invitations, grant edits), for delegation checks.
     *
     * @param  list<int>  $branchIds
     * @param  list<array{role_template_id: int, scope?: string|null, targets?: list<int>|null}>  $roles
     * @param  list<array{permission: string, effect: string, scope?: string|null, targets?: list<int>|null}>  $overrides
     * @return list<Grant>
     *
     * @throws InvalidArgumentException when the payload references an unknown/owner template, permission or scope
     */
    public function resolvePayload(?int $departmentId, array $branchIds, array $roles, array $overrides = []): array
    {
        $templates = RoleTemplate::query()
            ->with('grants')
            ->whereIn('id', array_column($roles, 'role_template_id'))
            ->get()
            ->keyBy('id');

        $resolvedRoles = [];
        foreach ($roles as $role) {
            $template = $templates->get($role['role_template_id']);
            if ($template === null || $template->is_owner) {
                throw new InvalidArgumentException('Unknown or non-assignable role template.');
            }
            $resolvedRoles[] = [
                'grants' => array_values($template->grants->map(fn ($g) => [$g->permission, $g->scope])->all()),
                'scope' => isset($role['scope'])
                    ? (Scope::tryFrom($role['scope']) ?? throw new InvalidArgumentException('Invalid role scope.'))
                    : null,
                'targets' => $role['targets'] ?? null,
            ];
        }

        $resolvedOverrides = [];
        foreach ($overrides as $override) {
            $permission = Permission::tryFrom($override['permission']);
            $effect = MemberGrantEffect::tryFrom($override['effect']);
            $scope = isset($override['scope']) ? Scope::tryFrom($override['scope']) : null;
            if ($permission === null || $effect === null
                || ($effect === MemberGrantEffect::Allow && ($scope === null || ! $permission->allowsScope($scope)))) {
                throw new InvalidArgumentException('Invalid member grant.');
            }
            $resolvedOverrides[] = ['permission' => $permission, 'effect' => $effect, 'scope' => $scope, 'targets' => $override['targets'] ?? null];
        }

        return $this->resolve($departmentId, $branchIds, $resolvedRoles, $resolvedOverrides);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<array{grants: list<array{0: Permission, 1: Scope}>, scope: Scope|null, targets: list<int>|null}>  $roles
     * @param  list<array{permission: Permission, effect: MemberGrantEffect, scope: Scope|null, targets: list<int>|null}>  $overrides
     * @return list<Grant>
     */
    public function resolve(?int $departmentId, array $branchIds, array $roles, array $overrides = []): array
    {
        $grants = [];
        $add = function (Permission $permission, Scope $scope, ?array $explicit) use (&$grants, $departmentId, $branchIds): void {
            // P-07 belongs to the owner only and is never delegable through roles or overrides.
            if ($permission === Permission::P07 || $permission->audience() !== Audience::Staff || ! $permission->allowsScope($scope)) {
                return;
            }
            $grant = new Grant($permission, $scope, $this->targetsFor($scope, $explicit, $departmentId, $branchIds));
            if ($scope->needsTargets() && $grant->targets === []) {
                return;
            }
            $grants[$grant->key()] = $grant;
        };

        foreach ($roles as $role) {
            foreach ($role['grants'] as [$permission, $templateScope]) {
                $assigned = $role['scope'];
                $useAssignment = $assigned !== null
                    && $templateScope->isOrganizational()
                    && $assigned->isOrganizational()
                    && $permission->allowsScope($assigned);

                $add(
                    $permission,
                    $useAssignment ? $assigned : $templateScope,
                    $useAssignment ? $role['targets'] : null,
                );
            }
        }

        $denied = [];
        foreach ($overrides as $override) {
            if ($override['effect'] === MemberGrantEffect::Deny) {
                $denied[$override['permission']->value] = true;
            } elseif ($override['scope'] !== null) {
                $add($override['permission'], $override['scope'], $override['targets']);
            }
        }

        return array_values(array_filter($grants, fn (Grant $g) => ! isset($denied[$g->permission->value])));
    }

    /**
     * @return list<Grant>
     */
    public function ownerGrants(): array
    {
        return array_map(
            fn (Permission $p) => new Grant($p, Scope::Tenant),
            PermissionCatalogue::forRole('owner'),
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $explicit
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    private function targetsFor(Scope $scope, ?array $explicit, ?int $departmentId, array $branchIds): array
    {
        if (! $scope->needsTargets()) {
            return [];
        }

        if ($explicit !== null) {
            return array_values(array_unique(array_map(intval(...), $explicit)));
        }

        return match ($scope) {
            Scope::Department => $departmentId === null ? [] : [$departmentId],
            Scope::Branches => $branchIds,
            Scope::Region => $branchIds === [] ? [] : array_values(array_map(
                intval(...),
                Branch::query()->whereIn('id', $branchIds)->whereNotNull('region_id')->distinct()->pluck('region_id')->all(),
            )),
            default => [],
        };
    }
}
