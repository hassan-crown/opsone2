<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\StateMachine;
use App\Models\Agreements\Agreement;
use App\Models\Interest;
use App\Models\Order;
use App\Models\StateTransition;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sales and orders pipeline (SHR-12, SHR-15): a converted interest or a created order is not a completed sale; the
 * funnel shows interest → order → agreement → completion → cancellation, each from its own source.
 */
final class SalesReport implements Report
{
    public const TERMINAL = ['draft', 'cancelled', 'closed', 'delivered', 'execution_finished'];

    public function key(): string
    {
        return 'sales';
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
        $r = new ReportResult('sales', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $orders = $s->orders();
        $created = (clone $orders)->whereBetween('orders.created_at', [$start, $end]);

        $interests = $s->branchFilter(ScopedQuery::apply(Interest::query(), $s->viewer, [Permission::P60], 'branch_id', null, null), 'interests.branch_id')
            ->whereBetween('converted_at', [$start, $end])->count();

        $firstApproved = DB::table('agreements')->select('order_id')->groupBy('order_id')
            ->havingRaw('MIN(approved_at) >= ? AND MIN(approved_at) <= ?', [$start, $end]);
        $approvedCount = (clone $orders)->whereIn('orders.id', $firstApproved)->count();

        $money = $this->agreed($s, $firstApproved);
        $transitions = fn (array $to) => StateTransition::query()->whereIn('machine', [StateMachine::M04->value, StateMachine::M05->value])
            ->where('subject_type', Support::morph())->whereIn('to_state', $to)->whereBetween('occurred_at', [$start, $end])
            ->whereIn('subject_id', Support::orderIds($orders))->distinct()->count('subject_id');

        $r->metric('sales.orders_created', (clone $created)->count())
            ->metric('sales.interests_converted', $interests)
            ->metric('sales.agreements_approved', $approvedCount)
            ->metric('sales.agreed_gross_minor', $money['gross'])
            ->metric('sales.agreed_net_minor', $money['net'])
            ->metric('sales.agreed_vat_minor', $money['vat'])
            ->metric('sales.completed', $transitions(['execution_finished', 'delivered']))
            ->metric('sales.cancelled', $transitions(['cancelled']))
            ->metric('sales.open_orders', (clone $orders)->whereNotIn('orders.status', self::TERMINAL)->count());

        $byStatus = (clone $created)->groupBy('orders.status')->orderBy('orders.status')
            ->selectRaw('orders.status as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('pipeline', 'bar', 'count', Support::enumPoints($byStatus));

        $list = (clone $created)->orderBy('orders.id')->limit(ReportResult::ROW_CAP + 1)->get();
        $values = $this->agreedByOrder($s, array_values(array_map('intval', $list->pluck('id')->all())));
        $rows = [];
        foreach ($list as $o) {
            $v = $values[$o->id] ?? null;
            $rows[] = [
                'number' => $o->number, 'kind' => Support::v($o->kind), 'status' => Support::v($o->status), 'branch' => Support::branchName($o->branch_id),
                'created_on' => Support::date($o->created_at),
                'agreed_gross_minor' => $v['gross'] ?? null, 'agreed_net_minor' => $v['net'] ?? null, 'agreed_vat_minor' => $v['vat'] ?? null,
            ];
        }

        return $r->table([
            ['key' => 'number', 'type' => 'text'], ['key' => 'kind', 'type' => 'enum'], ['key' => 'status', 'type' => 'enum'],
            ['key' => 'branch', 'type' => 'text'], ['key' => 'created_on', 'type' => 'date'],
            ['key' => 'agreed_gross_minor', 'type' => 'money'], ['key' => 'agreed_net_minor', 'type' => 'money'], ['key' => 'agreed_vat_minor', 'type' => 'money'],
        ], $rows);
    }

    /**
     * @return array{gross: int, net: int, vat: int}
     */
    private function agreed(ReportScope $s, Builder $firstApproved): array
    {
        if (! $s->amountsVisible()) {
            return ['gross' => 0, 'net' => 0, 'vat' => 0];
        }
        $row = Agreement::query()->where('status', 'active')
            ->whereIn('order_id', Support::orderIds($s->moneyOrders())->where('orders.status', '!=', 'cancelled'))
            ->whereIn('order_id', $firstApproved)
            ->selectRaw('COALESCE(SUM(total_minor),0) as g, COALESCE(SUM(net_minor),0) as n, COALESCE(SUM(vat_minor),0) as v')->toBase()->first();

        return ['gross' => (int) $row?->g, 'net' => (int) $row?->n, 'vat' => (int) $row?->v];
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{gross: int, net: int, vat: int}>
     */
    private function agreedByOrder(ReportScope $s, array $orderIds): array
    {
        if (! $s->amountsVisible() || $orderIds === []) {
            return [];
        }
        $allowed = Support::orderIds($s->moneyOrders())->whereIn('orders.id', $orderIds)->pluck('orders.id')->all();
        $out = [];
        foreach (Agreement::query()->where('status', 'active')->whereIn('order_id', $allowed)->get() as $a) {
            $out[$a->order_id] = ['gross' => (int) $a->total_minor, 'net' => (int) $a->net_minor, 'vat' => (int) $a->vat_minor];
        }

        return $out;
    }
}
