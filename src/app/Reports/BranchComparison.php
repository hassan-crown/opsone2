<?php

namespace App\Reports;

use App\Reports\Definitions\CollectionAgingReport;
use App\Reports\Definitions\SalesReport;
use App\Reports\Definitions\Support;
use App\Reports\Definitions\WorkflowReport;

/**
 * Branch comparison (SHR-13, P-61, license `branch_comparisons`): each branch's row is the same report run
 * restricted to that one branch, so a compared number is always the number the branch's own report shows.
 */
final class BranchComparison
{
    public static function build(ReportScope $s): ReportResult
    {
        $r = new ReportResult('compare', $s);
        $rows = [];
        foreach ($s->visibleBranchIds() as $branchId) {
            $one = new ReportScope($s->viewer, $s->from, $s->to, [$branchId]);
            $m = self::values((new SalesReport)->build($one)) + self::values((new WorkflowReport)->build($one));
            $money = $s->amountsVisible() ? self::values((new CollectionAgingReport)->build($one)) : [];
            $rows[] = [
                'branch' => Support::branchName($branchId),
                'orders_created' => $m['sales.orders_created'] ?? 0,
                'agreements_approved' => $m['sales.agreements_approved'] ?? 0,
                'completed' => $m['sales.completed'] ?? 0,
                'tasks_overdue' => $m['workflow.tasks_overdue'] ?? 0,
                'agreed_gross_minor' => $m['sales.agreed_gross_minor'] ?? null,
                'collected_net_minor' => $money['collection.net_minor'] ?? null,
            ];
        }
        $r->chart('orders_by_branch', 'bar', 'count', array_map(fn (array $row) => ['label' => $row['branch'], 'value' => $row['orders_created']], $rows));
        $r->chart('agreed_by_branch', 'bar', 'money', array_map(fn (array $row) => ['label' => $row['branch'], 'value' => (int) $row['agreed_gross_minor']], $rows));

        return $r->table([
            ['key' => 'branch', 'type' => 'text'], ['key' => 'orders_created', 'type' => 'int'], ['key' => 'agreements_approved', 'type' => 'int'],
            ['key' => 'completed', 'type' => 'int'], ['key' => 'tasks_overdue', 'type' => 'int'],
            ['key' => 'agreed_gross_minor', 'type' => 'money'], ['key' => 'collected_net_minor', 'type' => 'money'],
        ], $rows);
    }

    /**
     * @return array<string, int|float>
     */
    private static function values(ReportResult $result): array
    {
        $out = [];
        foreach ($result->toArray()['metrics'] as $metric) {
            $out[$metric['key']] = $metric['value'];
        }

        return $out;
    }
}
