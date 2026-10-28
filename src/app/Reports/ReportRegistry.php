<?php

namespace App\Reports;

use App\Access\Access;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Permission;
use App\Models\User;
use App\Reports\Definitions\AppointmentsReport;
use App\Reports\Definitions\CollectionAgingReport;
use App\Reports\Definitions\CommissionsReport;
use App\Reports\Definitions\InventoryReport;
use App\Reports\Definitions\ProcurementReport;
use App\Reports\Definitions\RefundsReport;
use App\Reports\Definitions\Report;
use App\Reports\Definitions\ReturnsReport;
use App\Reports\Definitions\SalesReport;
use App\Reports\Definitions\StoreReport;
use App\Reports\Definitions\WorkflowReport;

/**
 * The reports a viewer may open: P-60, the report's own grant (money reports) and its license capability. Branch
 * comparison is separate (P-61 carries the `branch_comparisons` capability through the Gate).
 */
final class ReportRegistry
{
    public const COMPARE = 'compare';

    /**
     * @return array<string, Report>
     */
    public static function all(): array
    {
        $list = [new SalesReport, new CollectionAgingReport, new RefundsReport, new CommissionsReport, new WorkflowReport,
            new AppointmentsReport, new InventoryReport, new ProcurementReport, new ReturnsReport, new StoreReport];

        $out = [];
        foreach ($list as $report) {
            $out[$report->key()] = $report;
        }

        return $out;
    }

    public static function find(string $key): ?Report
    {
        return self::all()[$key] ?? null;
    }

    public static function canOpen(User $user, Report $report): bool
    {
        if (! Access::can($user, Permission::P60)) {
            return false;
        }
        if ($report->requires() !== null && ! Access::can($user, $report->requires())) {
            return false;
        }

        return $report->capability() === null || app(LicenseCapabilities::class)->allows($report->capability());
    }

    /**
     * @return array<string, Report>
     */
    public static function availableTo(User $user): array
    {
        return array_filter(self::all(), fn (Report $r) => self::canOpen($user, $r));
    }

    public static function canCompare(User $user): bool
    {
        return Access::can($user, Permission::P61);
    }
}
