<?php

namespace App\Reports;

use App\Access\Access;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What one report run may see: the viewer's P-60 scope over orders (and over branch-bound records), the date range
 * (inclusive days in the facility time zone) and the optional branch filter. Money is a second, narrower question:
 * `moneyOrders()` further requires P-37 over the same order, so an amount never reaches a branch the viewer has no
 * money grant for (ADR-0026). Counts and amounts are therefore always computed over the sets the viewer is entitled to.
 */
final class ReportScope
{
    /**
     * @param  list<int>  $branchIds  empty = every branch the viewer may see
     */
    public function __construct(
        public readonly User $viewer,
        public readonly string $from,
        public readonly string $to,
        public readonly array $branchIds = [],
    ) {
        // Branch names are memoized per run only (a long-lived queue worker must never serve stale names).
        Definitions\Support::flush();
    }

    public static function defaultFrom(): string
    {
        return CarbonImmutable::parse(AppointmentBooking::today())->startOfMonth()->toDateString();
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->from, AppointmentBooking::timezone())->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->to, AppointmentBooking::timezone())->endOfDay()->setTimezone((string) config('app.timezone'));
    }

    /** Any P-37 grant at all: whether amounts may appear anywhere in a report for this viewer. */
    public function amountsVisible(): bool
    {
        return Access::can($this->viewer, Permission::P37);
    }

    /** Any P-82 grant: supplier cost, purchase value and inventory valuation. */
    public function costsVisible(): bool
    {
        return Access::can($this->viewer, Permission::P82);
    }

    public function commissionsVisible(): bool
    {
        return Access::can($this->viewer, Permission::P94);
    }

    /**
     * @return Builder<Order>
     */
    public function orders(): Builder
    {
        return $this->branchFilter(ScopedQuery::apply(Order::query(), $this->viewer, [Permission::P60], 'branch_id', null, null), 'orders.branch_id');
    }

    /**
     * @return Builder<Order>
     */
    public function moneyOrders(): Builder
    {
        return ScopedQuery::apply($this->orders(), $this->viewer, [Permission::P37], 'branch_id', null, null);
    }

    /**
     * Narrows any query over a branch-bound table to the viewer's P-60 scope plus the filter.
     *
     * @template T of Builder|QueryBuilder
     *
     * @param  T  $query
     * @return T
     */
    public function branchFilter(Builder|QueryBuilder $query, string $column): Builder|QueryBuilder
    {
        if ($this->branchIds !== []) {
            $query->whereIn($column, $this->branchIds);
        }

        return $query;
    }

    /**
     * Branches the viewer's P-60 grants cover (for the filter and the comparison rows).
     *
     * @return list<int>
     */
    public function visibleBranchIds(): array
    {
        $branches = ScopedQuery::apply(Branch::query(), $this->viewer, [Permission::P60], 'id', null, null);
        // Region grants resolve through the branch's region; a branch row matches by its own id for `branches` grants.
        $ids = array_values($branches->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());

        return $this->branchIds === [] ? $ids : array_values(array_intersect($ids, $this->branchIds));
    }
}
