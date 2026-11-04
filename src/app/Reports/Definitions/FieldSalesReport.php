<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\SalesChannel;
use App\Models\Commissions\CommissionEntry;
use App\Models\FieldSales\FieldAssignment;
use App\Models\FieldSales\SalesVisit;
use App\Models\User;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Field sales performance per rep (ADR-0041): leads and appointment requests dispatched, sales visits finished,
 * dispatched leads converted, field orders, booked sales (P-37) and commission accrued on them (P-94). Assignments
 * follow the viewer's P-60 branches; visits, orders and money the order scope.
 */
final class FieldSalesReport implements Report
{
    public function key(): string
    {
        return 'field_sales';
    }

    public function requires(): ?Permission
    {
        return null;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('field_sales', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $assignments = fn () => $s->branchFilter(ScopedQuery::apply(FieldAssignment::query(), $s->viewer, [Permission::P60], 'branch_id', null, null), 'field_assignments.branch_id');

        $leads = $this->perRep($assignments()->where('subject_type', FieldAssignment::INTEREST)->whereBetween('assigned_at', [$start, $end]), 'rep_id');
        $requests = $this->perRep($assignments()->where('subject_type', FieldAssignment::REQUEST)->whereBetween('assigned_at', [$start, $end]), 'rep_id');
        $conversions = $this->perRep($assignments()->where('subject_type', FieldAssignment::INTEREST)->where('outcome', 'converted')
            ->whereBetween('ended_at', [$start, $end]), 'rep_id');
        $visits = $this->perRep(SalesVisit::query()->whereIn('order_id', Support::orderIds($s->orders()))->whereNotNull('finished_at')
            ->whereBetween('finished_at', [$start, $end]), 'rep_id');
        $fieldOrders = (clone $s->orders())->where('orders.sales_channel', SalesChannel::FieldSales->value)->whereNotNull('orders.seller_id');
        $orders = $this->perRep((clone $fieldOrders)->whereBetween('orders.created_at', [$start, $end]), 'orders.seller_id');
        $commission = $s->commissionsVisible()
            ? $this->perRep(CommissionEntry::query()->join('orders', 'orders.id', '=', 'commission_entries.order_id')
                ->whereIn('commission_entries.order_id', Support::orderIds($fieldOrders))->whereColumn('commission_entries.user_id', 'orders.seller_id')
                ->whereBetween('commission_entries.created_at', [$start, $end]), 'commission_entries.user_id', 'SUM(commission_entries.amount_minor)')
            : [];

        $repIds = array_values(array_unique([...array_keys($leads), ...array_keys($requests), ...array_keys($conversions), ...array_keys($visits), ...array_keys($orders), ...array_keys($commission)]));
        $names = User::query()->whereIn('id', $repIds)->pluck('name', 'id');
        $rows = [];
        $booked = 0;
        foreach ($repIds as $id) {
            $repBooked = ChannelsReport::booked($s, $start, $end, $id)[SalesChannel::FieldSales->value];
            $booked += $repBooked;
            $assigned = $leads[$id] ?? 0;
            $rows[] = [
                'member' => (string) ($names[$id] ?? '#'.$id), 'leads' => $assigned, 'requests' => $requests[$id] ?? 0,
                'visits' => $visits[$id] ?? 0, 'conversions' => $conversions[$id] ?? 0,
                'conversion_percent' => $assigned === 0 ? 0.0 : round(($conversions[$id] ?? 0) * 100 / $assigned, 1),
                'orders' => $orders[$id] ?? 0, 'booked_minor' => $repBooked, 'commission_minor' => $commission[$id] ?? null,
            ];
        }
        usort($rows, fn (array $a, array $b) => [$b['booked_minor'], $b['orders'], $a['member']] <=> [$a['booked_minor'], $a['orders'], $b['member']]);

        $totalLeads = array_sum($leads);
        $totalConversions = array_sum($conversions);
        $r->metric('field_sales.leads_assigned', $totalLeads)
            ->metric('field_sales.requests_assigned', array_sum($requests))
            ->metric('field_sales.visits_done', array_sum($visits))
            ->metric('field_sales.conversions', $totalConversions)
            ->metric('field_sales.conversion_percent', $totalLeads === 0 ? 0.0 : round($totalConversions * 100 / $totalLeads, 1))
            ->metric('field_sales.orders', array_sum($orders))
            ->metric('field_sales.booked_minor', $booked)
            ->metric('field_sales.commission_minor', array_sum($commission));

        $r->chart('booked_by_rep', 'bar', 'money', array_map(fn (array $row) => ['label' => $row['member'], 'value' => $row['booked_minor']], $rows));
        $r->chart('visits_by_rep', 'bar', 'count', array_map(fn (array $row) => ['label' => $row['member'], 'value' => $row['visits']], $rows));

        $columns = [
            ['key' => 'member', 'type' => 'text'], ['key' => 'leads', 'type' => 'int'], ['key' => 'requests', 'type' => 'int'],
            ['key' => 'visits', 'type' => 'int'], ['key' => 'conversions', 'type' => 'int'], ['key' => 'conversion_percent', 'type' => 'percent'],
            ['key' => 'orders', 'type' => 'int'], ['key' => 'booked_minor', 'type' => 'money'],
        ];
        if ($s->commissionsVisible()) {
            $columns[] = ['key' => 'commission_minor', 'type' => 'money'];
        }

        return $r->table($columns, $rows);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  literal-string  $column
     * @param  literal-string  $aggregate
     * @return array<int, int>
     */
    private function perRep(Builder $query, string $column, string $aggregate = 'COUNT(*)'): array
    {
        return $query->toBase()->reorder()->groupBy($column)->selectRaw("{$column} as r, {$aggregate} as v")->pluck('v', 'r')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all();
    }
}
