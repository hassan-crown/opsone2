<?php

namespace App\Access;

use App\Access\Enums\Scope;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * P-04/P-05 rule: a granter may only grant actions they hold, within a scope no wider than their own.
 * Ownership is never granted through roles (P-07 has its own flow).
 *
 * Coverage of a requested grant R by one of the granter's grants G (same permission):
 * - G tenant covers everything;
 * - G region covers R region ⊆ G targets, R branches whose regions ⊆ G targets,
 *   R department whose served branches all lie in those regions;
 * - G branches covers R branches ⊆ G targets and R department whose served branches ⊆ G targets;
 * - G department covers R department ⊆ G targets;
 * - R own/assigned is covered by G of the same scope or any organizational G (the assignment itself is
 *   authorized separately within the granter's scope).
 */
final class DelegationGuard
{
    public function __construct(private readonly Authorizer $authorizer) {}

    /**
     * @param  list<Grant>  $requested
     *
     * @throws AuthorizationException
     */
    public function assertCanGrant(User $granter, array $requested): void
    {
        $own = $this->authorizer->effectiveGrants($granter);

        foreach ($requested as $grant) {
            if ($grant->permission === Permission::P07 || ! $this->isCovered($grant, $own)) {
                throw new AuthorizationException(__('access.denied.delegation', ['permission' => $grant->permission->value]));
            }
        }
    }

    /**
     * @param  list<Grant>  $own
     */
    private function isCovered(Grant $requested, array $own): bool
    {
        foreach ($own as $grant) {
            if ($grant->permission === $requested->permission && $this->covers($grant, $requested)) {
                return true;
            }
        }

        return false;
    }

    private function covers(Grant $g, Grant $r): bool
    {
        if ($g->scope === Scope::Tenant) {
            return true;
        }

        if ($r->scope === Scope::Own || $r->scope === Scope::Assigned) {
            return $g->scope === $r->scope || $g->scope->isOrganizational();
        }

        $targetsWithin = fn (array $ids, array $allowed): bool => $ids !== [] && array_diff($ids, $allowed) === [];

        return match ($g->scope) {
            Scope::Region => match ($r->scope) {
                Scope::Region => $targetsWithin($r->targets, $g->targets),
                Scope::Branches => $targetsWithin($this->regionsOfBranches($r->targets), $g->targets),
                Scope::Department => $targetsWithin($this->regionsOfBranches($this->branchesOfDepartments($r->targets)), $g->targets),
                default => false,
            },
            Scope::Branches => match ($r->scope) {
                Scope::Branches => $targetsWithin($r->targets, $g->targets),
                Scope::Department => $targetsWithin($this->branchesOfDepartments($r->targets), $g->targets),
                default => false,
            },
            Scope::Department => $r->scope === Scope::Department && $targetsWithin($r->targets, $g->targets),
            default => false,
        };
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<int>
     */
    private function regionsOfBranches(array $branchIds): array
    {
        $regions = Branch::query()->whereIn('id', $branchIds)->pluck('region_id');

        // A branch without a region can never be inside a region scope.
        return $regions->contains(null) || $regions->isEmpty()
            ? []
            : array_values(array_unique(array_map(intval(...), $regions->all())));
    }

    /**
     * @param  list<int>  $departmentIds
     * @return list<int>
     */
    private function branchesOfDepartments(array $departmentIds): array
    {
        return array_values(array_unique(array_map(
            intval(...),
            Department::query()->whereIn('departments.id', $departmentIds)
                ->join('branch_department', 'branch_department.department_id', '=', 'departments.id')
                ->pluck('branch_department.branch_id')->all(),
        )));
    }
}
