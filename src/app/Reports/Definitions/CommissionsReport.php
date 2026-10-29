<?php

namespace App\Reports\Definitions;

use App\Access\Permission;
use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\CommissionPayout;
use App\Models\Commissions\CommissionPayoutLine;
use App\Models\User;
use App\Reports\ReportResult;
use App\Reports\ReportScope;

/**
 * Commissions (SHR-12, SHR-15): accruals are append-only signed differences, so a cancellation lowers the net accrued
 * without erasing what was paid. Needs P-94 on top of the order scope.
 */
final class CommissionsReport implements Report
{
    public function key(): string
    {
        return 'commissions';
    }

    public function requires(): Permission
    {
        return Permission::P94;
    }

    public function capability(): ?string
    {
        return null;
    }

    public function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('commissions', $s);
        $ids = Support::orderIds($s->orders());
        $start = Support::at($s->start());
        $end = Support::at($s->end());

        $entries = CommissionEntry::query()->whereIn('order_id', $ids);
        $accrued = (int) (clone $entries)->whereBetween('created_at', [$start, $end])->sum('amount_minor');
        $untilEnd = (int) (clone $entries)->where('created_at', '<=', $end)->sum('amount_minor');

        $paidQuery = fn () => CommissionPayout::query()->where('status', 'paid')
            ->whereIn('id', CommissionPayoutLine::query()->whereIn('order_id', $ids)->select('payout_id'));
        $paid = (int) CommissionPayoutLine::query()->whereIn('order_id', $ids)
            ->whereIn('payout_id', CommissionPayout::query()->where('status', 'paid')->whereBetween('paid_on', [$s->from, $s->to])->select('id'))->sum('amount_minor');
        $paidToEnd = (int) CommissionPayoutLine::query()->whereIn('order_id', $ids)
            ->whereIn('payout_id', CommissionPayout::query()->where('status', 'paid')->where('paid_on', '<=', $s->to)->select('id'))->sum('amount_minor');
        unset($paidQuery);

        $r->metric('commissions.accrued_minor', $accrued)
            ->metric('commissions.paid_minor', $paid)
            ->metric('commissions.balance_minor', $untilEnd - $paidToEnd);

        $perUser = (clone $entries)->whereBetween('created_at', [$start, $end])->groupBy('user_id')->orderBy('user_id')
            ->selectRaw('user_id as u, SUM(amount_minor) as s')->pluck('s', 'u')->map(fn ($v) => (int) $v)->all();
        $names = User::query()->whereIn('id', array_keys($perUser))->pluck('name', 'id');
        $rows = [];
        foreach ($perUser as $uid => $sum) {
            $rows[] = ['member' => (string) ($names[$uid] ?? '#'.$uid), 'accrued_minor' => $sum];
        }
        $r->chart('by_member', 'bar', 'money', array_map(fn (array $row) => ['label' => $row['member'], 'value' => $row['accrued_minor']], $rows));

        return $r->table([['key' => 'member', 'type' => 'text'], ['key' => 'accrued_minor', 'type' => 'money']], $rows);
    }
}
