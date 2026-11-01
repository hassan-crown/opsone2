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
        $branches = self::rows($s);
        $r->chart('orders_by_branch', 'bar', 'count', array_map(fn (array $row) => ['label' => $row['branch'], 'value' => $row['orders_created']], $branches));
        $r->chart('agreed_by_branch', 'bar', 'money', array_map(fn (array $row) => ['label' => $row['branch'], 'value' => (int) $row['agreed_gross_minor']], $branches));
        $rows = array_map(fn (array $row) => array_diff_key($row, ['branch_id' => 1]), $branches);

        return $r->table([
            ['key' => 'branch', 'type' => 'text'], ['key' => 'orders_created', 'type' => 'int'], ['key' => 'agreements_approved', 'type' => 'int'],
            ['key' => 'completed', 'type' => 'int'], ['key' => 'tasks_overdue', 'type' => 'int'],
            ['key' => 'agreed_gross_minor', 'type' => 'money'], ['key' => 'collected_net_minor', 'type' => 'money'],
        ], $rows);
    }

    /**
     * One row per visible branch (with `branch_id`, which the export table drops), each the same reports run for that
     * branch alone. Summary only: the sub-reports' row listings are not needed here.
     *
     * @return list<array{branch_id: int, branch: string, orders_created: int|float, agreements_approved: int|float, completed: int|float, tasks_overdue: int|float, agreed_gross_minor: int|float|null, collected_net_minor: int|float|null}>
     */
    public static function rows(ReportScope $s): array
    {
        $rows = [];
        foreach ($s->visibleBranchIds() as $branchId) {
            $one = new ReportScope($s->viewer, $s->from, $s->to, [$branchId], false);
            $m = self::values((new SalesReport)->build($one)) + self::values((new WorkflowReport)->build($one));
            $money = $s->amountsVisible() ? self::values((new CollectionAgingReport)->build($one)) : [];
            $rows[] = [
                'branch_id' => $branchId,
                'branch' => Support::branchName($branchId),
                'orders_created' => $m['sales.orders_created'] ?? 0,
                'agreements_approved' => $m['sales.agreements_approved'] ?? 0,
                'completed' => $m['sales.completed'] ?? 0,
                'tasks_overdue' => $m['workflow.tasks_overdue'] ?? 0,
                'agreed_gross_minor' => $m['sales.agreed_gross_minor'] ?? null,
                'collected_net_minor' => $money['collection.net_minor'] ?? null,
            ];
        }

        return $rows;
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
