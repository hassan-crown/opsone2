<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\PaymentCorrection;
use App\Models\Finance\Refund;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Refunds, corrections and disputes (SHR-12): all money, so P-37 over each order. «Executed» is the manual payout
 * recorded by finance; a requested refund is not money that left.
 */
final class RefundsReport implements Report
{
    public function key(): string
    {
        return 'refunds';
    }

    public function requires(): Permission
    {
        return Permission::P37;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('refunds', $s);
        $ids = Support::orderIds($s->moneyOrders());
        $start = Support::at($s->start());
        $end = Support::at($s->end());
        $refunds = Refund::query()->whereIn('order_id', $ids);

        $executed = (clone $refunds)->where('status', 'executed')->whereBetween('executed_at', [$start, $end]);
        $r->metric('refunds.requested_count', (clone $refunds)->whereBetween('created_at', [$start, $end])->count())
            ->metric('refunds.executed_count', (clone $executed)->count())
            ->metric('refunds.executed_minor', (int) (clone $executed)->sum('amount_minor'))
            ->metric('refunds.open_minor', (int) (clone $refunds)->whereIn('status', ['requested', 'in_progress', 'unresolved'])->sum('amount_minor'))
            ->metric('refunds.corrections_count', PaymentCorrection::query()->whereIn('order_id', $ids)->whereBetween('created_at', [$start, $end])->count())
            ->metric('refunds.open_disputes', FinancialDispute::query()->whereIn('order_id', $ids)->whereNull('resolved_at')->count());

        $byMethod = (clone $executed)->groupBy('method')->orderBy('method')->selectRaw('method as m, SUM(amount_minor) as s')->pluck('s', 'm')->map(fn ($v) => (int) $v)->all();
        $r->chart('by_method', 'bar', 'money', Support::enumPoints($byMethod));

        $list = (clone $refunds)->with('order')->where(fn ($q) => $q->whereBetween('created_at', [$start, $end])->orWhereBetween('executed_at', [$start, $end]))
            ->orderBy('id')->limit(ReportResult::ROW_CAP + 1)->get();
        $rows = $list->map(fn (Refund $f) => [
            'number' => $f->number, 'order' => $f->order->number, 'method' => Support::v($f->method), 'status' => Support::v($f->status),
            'amount_minor' => $f->amount_minor, 'created_on' => Support::date($f->created_at), 'executed_on' => Support::date($f->executed_at),
        ])->all();

        return $r->table([
            ['key' => 'number', 'type' => 'text'], ['key' => 'order', 'type' => 'text'], ['key' => 'method', 'type' => 'enum'], ['key' => 'status', 'type' => 'enum'],
            ['key' => 'amount_minor', 'type' => 'money'], ['key' => 'created_on', 'type' => 'date'], ['key' => 'executed_on', 'type' => 'date'],
        ], $rows);
    }
}
