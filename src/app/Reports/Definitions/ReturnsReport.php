<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\Settlement\SettlementItem;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Returns and cancellations (SHR-12, SHR-15): a cancellation corrects the sale; it never deletes what was collected
 * or paid. Only accepted cancellations count, by decision date.
 */
final class ReturnsReport implements Report
{
    public function key(): string
    {
        return 'returns';
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
        $r = new ReportResult('returns', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $ids = Support::orderIds($s->orders());
        $moneyIds = Support::orderIds($s->moneyOrders());

        $returns = OrderReturn::query()->whereIn('order_id', $ids)->whereBetween('created_at', [$start, $end]);
        $cancels = OrderCancellation::query()->whereIn('order_id', $ids)->where('status', 'accepted')->whereBetween('decided_at', [$start, $end]);

        $r->metric('returns.requested', (clone $returns)->count())
            ->metric('returns.accepted_milli', (int) OrderReturnLine::query()->whereIn('order_return_id', (clone $returns)->select('id'))->sum('accepted_milli'))
            ->metric('returns.refund_value_minor', (int) (clone $returns)->whereIn('order_id', $moneyIds)->sum('settlement_minor'))
            ->metric('cancellations.confirmed', (clone $cancels)->count())
            ->metric('cancellations.partial', (clone $cancels)->where('scope', 'partial')->count())
            ->metric('cancellations.retained_minor', (int) (clone $cancels)->whereIn('order_id', $moneyIds)->sum('retained_minor'))
            ->metric('cancellations.open_settlement_items', SettlementItem::query()->whereIn('order_id', $moneyIds)->where('status', SettlementItem::OPEN)->count());

        $byStatus = (clone $returns)->groupBy('status')->orderBy('status')->selectRaw('status as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('returns_by_status', 'bar', 'count', Support::enumPoints($byStatus));
        $byScope = (clone $cancels)->groupBy('scope')->orderBy('scope')->selectRaw('scope as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('cancellations_by_scope', 'bar', 'count', Support::enumPoints($byScope));

        $rows = [];
        foreach ((clone $returns)->with('order')->orderBy('id')->limit(ReportResult::ROW_CAP)->get() as $x) {
            $rows[] = ['type' => 'return', 'number' => $x->number, 'order' => $x->order->number, 'state' => Support::v($x->status), 'value_minor' => $x->settlement_minor, 'on' => Support::date($x->created_at)];
        }
        foreach ((clone $cancels)->with('order')->orderBy('id')->limit(ReportResult::ROW_CAP)->get() as $x) {
            $rows[] = ['type' => 'cancellation', 'number' => $x->number, 'order' => $x->order->number, 'state' => Support::v($x->scope), 'value_minor' => $x->retained_minor, 'on' => Support::date($x->decided_at)];
        }
        // Money cells follow the P-37 scope of their own order, not just the column's grant.
        $moneyOrderNumbers = array_flip((clone $s->moneyOrders())->whereIn('orders.number', array_column($rows, 'order'))->pluck('orders.number')->all());
        foreach ($rows as &$row) {
            if (! isset($moneyOrderNumbers[$row['order']])) {
                $row['value_minor'] = null;
            }
        }
        unset($row);

        return $r->table([
            ['key' => 'type', 'type' => 'enum'], ['key' => 'number', 'type' => 'text'], ['key' => 'order', 'type' => 'text'],
            ['key' => 'state', 'type' => 'enum'], ['key' => 'value_minor', 'type' => 'money'], ['key' => 'on', 'type' => 'date'],
        ], $rows);
    }
}
