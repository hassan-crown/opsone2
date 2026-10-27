<?php

namespace App\Access;

use App\Access\Contracts\ScopedRecord;
use App\Access\Enums\Scope;
use App\Models\Branch;
use App\Models\User;

/**
 * Decides whether a record falls inside a grant's scope, using only server-side facts from the record.
 */
final class ScopeResolver
{
    public function covers(Grant $grant, User $user, ?object $record): bool
    {
        if ($record === null || $grant->scope === Scope::Tenant) {
            return true;
        }

        if ($record instanceof User) {
            return $grant->scope === Scope::Own && $record->id === $user->id;
        }

        if (! $record instanceof ScopedRecord) {
            return false;
        }

        return match ($grant->scope) {
            Scope::Own => $record->accessOwnerId() !== null && $record->accessOwnerId() === $user->id,
            Scope::Assigned => in_array($user->id, $record->accessAssigneeIds(), true),
            Scope::Department => $record->accessDepartmentId() !== null
                && in_array($record->accessDepartmentId(), $grant->targets, true),
            Scope::Branches => $this->allWithin($record->accessBranchIds(), $grant->targets),
            Scope::Region => $this->allWithin($this->regionsOf($record->accessBranchIds()), $grant->targets),
        };
    }

    /**
     * @param  list<int>|null  $ids
     * @param  list<int>  $targets
     */
    private function allWithin(?array $ids, array $targets): bool
    {
        return $ids !== null && $ids !== [] && array_diff($ids, $targets) === [];
    }

    /**
     * Regions of the given branches, or null when any branch has no region (then no region scope covers it).
     *
     * @param  list<int>  $branchIds
     * @return list<int>|null
     */
    private function regionsOf(array $branchIds): ?array
    {
        if ($branchIds === []) {
            return [];
        }

        $regions = Branch::query()->whereIn('id', $branchIds)->pluck('region_id', 'id');

        if ($regions->count() !== count(array_unique($branchIds)) || $regions->contains(null)) {
            return null;
        }

        return array_values(array_unique(array_map(intval(...), $regions->all())));
    }
}
