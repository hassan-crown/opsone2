<?php

namespace App\Finance;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Enums\JournalKind;
use App\Models\Finance\MoneyEntry;
use App\Models\Order;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;

/**
 * ReportCollection (SHR-12, FIN-01): what is due and how old, from confirmed money only — a proof under review is
 * shown beside a due, never counted. Ages by due date: not overdue, 1–7, 8–30, over 30 days. Collected this month
 * from the ledger (collections; reversals from 6c subtract). Scoped by P-37 over each order and the branch filter.
 */
final class CollectionReport
{
    public const BUCKETS = ['not_overdue', 'd1_7', 'd8_30', 'd30_plus'];

    /**
     * @return array<string, mixed>
     */
    public static function build(User $viewer, ?int $branchId = null): array
    {
        $orders = ScopedQuery::apply(Order::query(), $viewer, [Permission::P37]);
        if ($branchId !== null) {
            $orders->where('orders.branch_id', $branchId);
        }
        $today = AppointmentBooking::today();
        $buckets = array_fill_keys(self::BUCKETS, ['amount_minor' => 0, 'count' => 0]);
        $overdue = [];

        foreach (OpenDues::of(clone $orders) as $row) {
            if ($row['due_on'] === null) {
                continue;
            }
            $age = $row['due_on'] < $today ? (int) CarbonImmutable::parse($row['due_on'])->diffInDays(CarbonImmutable::parse($today)) : 0;
            $bucket = match (true) {
                $age === 0 => 'not_overdue',
                $age <= 7 => 'd1_7',
                $age <= 30 => 'd8_30',
                default => 'd30_plus',
            };
            $buckets[$bucket]['amount_minor'] += $row['remaining'];
            $buckets[$bucket]['count']++;
            if ($age > 0) {
                $overdue[] = [
                    'order' => ['id' => $row['order']->id, 'number' => $row['order']->number],
                    'customer' => $row['order']->customer->name,
                    'due' => $row['due']->name->get(),
                    'amount_minor' => $row['remaining'],
                    'age_days' => $age,
                ];
            }
        }
        usort($overdue, fn (array $a, array $b) => $b['age_days'] <=> $a['age_days']);

        $monthStart = CarbonImmutable::parse($today)->startOfMonth()->startOfDay();
        $ids = (clone $orders)->select('orders.id');
        $month = fn (array $kinds) => (int) MoneyEntry::query()->join('money_journals', 'money_journals.id', '=', 'money_entries.journal_id')
            ->whereIn('money_journals.kind', $kinds)->where('money_journals.created_at', '>=', $monthStart)
            ->whereIn('money_entries.order_id', $ids)->sum('money_entries.amount_minor');
        $collected = $month([JournalKind::Collection->value]);
        $net = $month([JournalKind::Collection->value, JournalKind::Reversal->value, JournalKind::Transfer->value]);

        return [
            'buckets' => $buckets,
            'overdue' => array_slice($overdue, 0, 100),
            'overdue_total_minor' => array_sum(array_column($overdue, 'amount_minor')),
            'month' => ['collected_minor' => $collected, 'corrections_minor' => $net - $collected, 'net_minor' => $net],
        ];
    }
}
