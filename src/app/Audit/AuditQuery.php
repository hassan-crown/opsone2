<?php

namespace App\Audit;

use App\Access\Enums\Scope;
use App\Access\Grant;
use App\Models\AuditEvent;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Turns a viewer's P-63 grants into a query over audit events, with the same rules as App\Access\ScopeResolver:
 * a branch/region grant sees events whose branches are all inside it (events with no branch are facility-wide and
 * need a facility scope), a department grant sees events of its departments.
 */
final class AuditQuery
{
    /**
     * @param  list<Grant>  $grants
     * @return Builder<AuditEvent>
     */
    public static function visibleTo(array $grants): Builder
    {
        $query = AuditEvent::query();

        if ($grants === []) {
            return $query->whereRaw('1 = 0');
        }
        foreach ($grants as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return $query;
            }
        }

        return $query->where(function (Builder $outer) use ($grants) {
            $outer->whereRaw('1 = 0');
            foreach ($grants as $grant) {
                match ($grant->scope) {
                    Scope::Department => $outer->orWhereIn('department_id', $grant->targets),
                    Scope::Branches => $outer->orWhere(fn (Builder $q) => self::withinBranches($q, $grant->targets)),
                    Scope::Region => $outer->orWhere(fn (Builder $q) => self::withinBranches(
                        $q,
                        array_values(array_map(intval(...), Branch::query()->whereIn('region_id', $grant->targets)->pluck('id')->all())),
                    )),
                    default => null,
                };
            }
        });
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  list<int>  $branchIds
     */
    private static function withinBranches(Builder $query, array $branchIds): void
    {
        $query->whereExists(fn (QueryBuilder $q) => $q->from('audit_event_branches')
            ->whereColumn('audit_event_branches.audit_event_id', 'audit_events.id'))
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('audit_event_branches')
                ->whereColumn('audit_event_branches.audit_event_id', 'audit_events.id')
                ->whereNotIn('audit_event_branches.branch_id', $branchIds === [] ? [0] : $branchIds));
    }
}
