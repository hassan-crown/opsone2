<?php

namespace App\Finance;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Order;
use App\Models\User;
use App\Reports\CollectionMetrics;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;

/**
 * ReportCollection (SHR-12, FIN-01): what is due and how old, from confirmed money only — a proof under review is
 * shown beside a due, never counted. Ages by due date: not overdue, 1–7, 8–30, over 30 days. Collected this month
 * from the ledger (collections; reversals from 6c subtract). Scoped by P-37 over each order and the branch filter.
 */
final class CollectionReport
{
    public const BUCKETS = CollectionMetrics::BUCKETS;

    /**
     * @return array<string, mixed>
     */
    public static function build(User $viewer, ?int $branchId = null): array
    {
        $orders = ScopedQuery::apply(Order::query(), $viewer, [Permission::P37]);
        if ($branchId !== null) {
            $orders->where('orders.branch_id', $branchId);
        }
        $aging = CollectionMetrics::aging($orders);
        $today = AppointmentBooking::today();
        $monthStart = CarbonImmutable::parse($today)->startOfMonth()->startOfDay();
        $m = CollectionMetrics::movements($orders, $monthStart, CarbonImmutable::parse($today)->endOfDay());
        $overdue = array_map(fn (array $o) => [
            'order' => ['id' => $o['order_id'], 'number' => $o['order']], 'customer' => $o['customer'],
            'due' => $o['due'], 'amount_minor' => $o['amount_minor'], 'age_days' => $o['age_days'],
        ], $aging['overdue']);

        return [
            'buckets' => $aging['buckets'],
            'overdue' => array_slice($overdue, 0, 100),
            'overdue_total_minor' => $aging['overdue_minor'],
            'month' => ['collected_minor' => $m['collected'], 'corrections_minor' => $m['net'] - $m['collected'], 'net_minor' => $m['net']],
        ];
    }
}
