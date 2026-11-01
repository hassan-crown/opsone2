<?php

namespace App\Reports\Dashboard;

use App\Http\Controllers\Calendar\CalendarController;
use App\Http\Controllers\Customers\InterestController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Reports\ReportsController;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;

/**
 * Drill-down targets of the dashboard: each tile/chart opens the existing list or report already filtered to the same
 * period and branches. Plain URLs (the destination re-checks the viewer's own permission and scope).
 */
final class DashboardLinks
{
    /**
     * @return array<string, string>
     */
    public static function for(ReportScope $s): array
    {
        $range = ['from' => $s->from, 'to' => $s->to] + ($s->branchIds === [] ? [] : ['branch_ids' => $s->branchIds]);
        $branch = count($s->branchIds) === 1 ? ['branch' => $s->branchIds[0]] : [];
        $report = fn (string $key) => route('reports.show', ['report' => $key] + $range, false);

        return [
            'sales' => $report('sales'),
            'collection' => route('reports.collection', $branch === [] ? [] : ['branch_id' => $s->branchIds[0]], false),
            'workflow' => $report('workflow'),
            'orders_open' => action([OrderController::class, 'index'], $branch + ['status' => 'in_progress'], false),
            'orders' => action([OrderController::class, 'index'], $branch, false),
            'interests' => action([InterestController::class, 'index'], $branch, false),
            'interests_converted' => action([InterestController::class, 'index'], $branch + ['status' => 'converted'], false),
            'installations' => action([CalendarController::class, 'index'], $branch + ['date' => AppointmentBooking::today()], false),
            'compare' => action([ReportsController::class, 'compare'], $range, false),
        ];
    }

    /** Orders of one branch (branch bars). */
    public static function branch(int $id): string
    {
        return action([OrderController::class, 'index'], ['branch' => $id], false);
    }

    /** Orders assigned to one person (salespeople bars). */
    public static function assignee(int $id): string
    {
        return action([OrderController::class, 'index'], ['assignee' => $id], false);
    }

    /** Sales report of one calendar month, same branches (trend bars). */
    public static function salesMonth(ReportScope $s, string $from, string $to): string
    {
        return route('reports.show', ['report' => 'sales', 'from' => $from, 'to' => $to] + ($s->branchIds === [] ? [] : ['branch_ids' => $s->branchIds]), false);
    }
}
