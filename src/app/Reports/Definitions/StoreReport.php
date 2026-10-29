<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Store\StoreCheckout;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Store (SHR-12, domain 07): checkouts of the ready-goods store, by the order's branch. Listed only on plans with the
 * `ready_goods` capability.
 */
final class StoreReport implements Report
{
    public function key(): string
    {
        return 'store';
    }

    public function requires(): ?Permission
    {
        return null;
    }

    public function capability(): string
    {
        return 'ready_goods';
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('store', $s);
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $q = StoreCheckout::query()->whereIn('order_id', Support::orderIds($s->orders()));
        $paid = (clone $q)->where('status', 'paid')->whereBetween('paid_at', [$start, $end]);
        $paidMoney = (clone $paid)->whereIn('order_id', Support::orderIds($s->moneyOrders()));
        $created = (clone $q)->whereBetween('created_at', [$start, $end]);
        $createdCount = (clone $created)->count();
        $paidOfCreated = (clone $created)->where('status', 'paid')->count();

        $r->metric('store.checkouts', $createdCount)
            ->metric('store.paid', (clone $paid)->count())
            ->metric('store.paid_total_minor', (int) (clone $paidMoney)->sum('total_minor'))
            ->metric('store.discount_minor', (int) (clone $paidMoney)->sum('discount_minor'))
            ->metric('store.conversion_percent', $createdCount === 0 ? 0.0 : round($paidOfCreated * 100 / $createdCount, 1));

        $byStatus = (clone $created)->groupBy('status')->orderBy('status')->selectRaw('status as s, COUNT(*) as c')->pluck('c', 's')->map(fn ($c) => (int) $c)->all();
        $r->chart('by_status', 'bar', 'count', Support::enumPoints($byStatus));

        $moneyIds = array_flip(Support::orderIds($s->moneyOrders())->pluck('orders.id')->all());
        $rows = (clone $created)->with('order')->orderBy('id')->limit(ReportResult::ROW_CAP + 1)->get()->map(fn (StoreCheckout $c) => [
            'number' => $c->number, 'order' => $c->order->number, 'status' => Support::v($c->status), 'created_on' => Support::date($c->created_at),
            'total_minor' => isset($moneyIds[$c->order_id]) ? $c->total_minor : null, 'discount_minor' => isset($moneyIds[$c->order_id]) ? $c->discount_minor : null,
        ])->all();

        return $r->table([
            ['key' => 'number', 'type' => 'text'], ['key' => 'order', 'type' => 'text'], ['key' => 'status', 'type' => 'enum'], ['key' => 'created_on', 'type' => 'date'],
            ['key' => 'total_minor', 'type' => 'money'], ['key' => 'discount_minor', 'type' => 'money'],
        ], $rows);
    }
}
