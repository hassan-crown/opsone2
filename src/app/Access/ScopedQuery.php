<?php

namespace App\Access;

use App\Access\Enums\Scope;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows a list query of single-branch records to what the user's grants cover, mirroring ScopeResolver
 * (lists, search and counts must never show what a direct open would refuse). Several permissions are OR-ed.
 */
final class ScopedQuery
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<Permission>  $permissions
     * @return Builder<TModel>
     */
    public static function apply(
        Builder $query,
        User $user,
        array $permissions,
        string $branchColumn = 'branch_id',
        ?string $ownerColumn = 'created_by',
        ?string $assigneeColumn = 'assignee_id',
    ): Builder {
        $grants = [];
        foreach ($permissions as $permission) {
            array_push($grants, ...Access::grantsFor($user, $permission));
        }

        foreach ($grants as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return $query;
            }
        }

        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $q) use ($grants, $user, $table, $branchColumn, $ownerColumn, $assigneeColumn) {
            foreach ($grants as $grant) {
                match ($grant->scope) {
                    Scope::Own => $ownerColumn === null ? null : $q->orWhere($table.'.'.$ownerColumn, $user->id),
                    Scope::Assigned => $assigneeColumn === null ? null : $q->orWhere($table.'.'.$assigneeColumn, $user->id),
                    Scope::Branches => $q->orWhereIn($table.'.'.$branchColumn, $grant->targets),
                    Scope::Region => $q->orWhereIn(
                        $table.'.'.$branchColumn,
                        Branch::query()->whereIn('region_id', $grant->targets)->select('id'),
                    ),
                    default => null,
                };
            }
            // A query with only unsupported scopes (e.g. department) must match nothing.
            $q->orWhereRaw('1 = 0');
        });
    }
}
