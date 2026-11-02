<?php

namespace App\Reports\Dashboard;

use App\Access\Access;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Models\Appointment;
use App\Models\Interest;
use App\Models\OrderStage;
use App\Models\Task;
use App\Models\User;
use App\Reports\BranchComparison;
use App\Reports\CollectionMetrics;
use App\Reports\Definitions\CollectionAgingReport;
use App\Reports\Definitions\SalesReport;
use App\Reports\Definitions\Support;
use App\Reports\Definitions\WorkflowReport;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The owner / general-manager dashboard (ADR-0031). Every number is the P9 metric of the same name, run summary-only
 * over the viewer's scope (ReportScope: P-60 over the order's branch, money further narrowed by P-37); the few
 * figures P9 lacked are registered in MetricCatalogue as `dashboard.*`. Nothing here reads a counter column.
 *
 * Access: P-60 ∧ P-37. The owner and general manager hold both at tenant scope, a branch manager at branch scope, so
 * the same code shows each of them their own slice. Roles without money (supervisor) or without reports (sales,
 * executors) get 403, and an amount the viewer may not see is absent from the payload, never 0.
 */
final class ExecutiveDashboard
{
    public const PERIODS = ['month', 'last30', 'quarter', 'custom'];

    public const TREND_MONTHS = 6;

    public const LEADERS = 5;

    /** Cap of in-progress stages read for the stage chart and late-order count (one row per active order). */
    private const STAGE_CAP = 5000;

    public static function canView(User $user): bool
    {
        return Access::can($user, Permission::P60) && Access::can($user, Permission::P37);
    }

    /**
     * Inclusive facility-day range of a period: this month, the last 30 days, this quarter (each up to today).
     *
     * @return array{0: string, 1: string}
     */
    public static function range(string $period): array
    {
        $today = CarbonImmutable::parse(AppointmentBooking::today());
        $from = match ($period) {
            'last30' => $today->subDays(29),
            'quarter' => $today->startOfQuarter(),
            default => $today->startOfMonth(),
        };

        return [$from->toDateString(), $today->toDateString()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(ReportScope $viewerScope, string $period): array
    {
        // Summary only: the dashboard never needs a report's row listing.
        $s = new ReportScope($viewerScope->viewer, $viewerScope->from, $viewerScope->to, $viewerScope->branchIds, false);
        $stages = self::stages($s);
        $installation = InstallationMetrics::build($s);
        $m = self::metrics($s, $stages['late'], $installation);

        return [
            'period' => $period,
            'range' => ['from' => $s->from, 'to' => $s->to],
            'branch_ids' => $s->branchIds,
            'metrics' => $m['metrics'],
            'funnel' => $m['funnel'],
            'trend' => self::trend($s),
            'stages' => $stages['by_stage'],
            'branches' => self::branches($s),
            'salespeople' => self::salespeople($s),
            'executors' => self::executors($s),
            'installation_teams' => $installation['teams'],
            'links' => DashboardLinks::for($s),
        ];
    }

    /**
     * The KPI metrics (existing P9 keys passed through, plus `dashboard.*`) and the funnel counts.
     *
     * @param  array{count: int, first_visit_percent: float|null, avg_minutes: int|null}  $installation
     * @return array{metrics: list<array{key: string, kind: string, value: int|float}>, funnel: list<array{key: string, value: int}>}
     */
    private static function metrics(ReportScope $s, int $lateOrders, array $installation): array
    {
        $values = [];
        foreach ([(new SalesReport)->build($s), (new CollectionAgingReport)->build($s), (new WorkflowReport)->build($s)] as $result) {
            foreach ($result->toArray()['metrics'] as $metric) {
                $values[$metric['key']] = $metric['value'];
            }
        }

        $r = new ReportResult('dashboard', $s);
        foreach (['sales.agreed_gross_minor', 'collection.collected_minor', 'collection.open_minor', 'collection.overdue_minor',
            'sales.orders_created', 'sales.open_orders', 'workflow.tasks_overdue'] as $key) {
            if (isset($values[$key])) {
                $r->metric($key, $values[$key]);
            }
        }

        if ($s->amountsVisible()) {
            $agreed = (new SalesReport)->agreed($s, SalesReport::firstApproved(Support::at($s->start()), Support::at($s->end())));
            $r->metric('dashboard.avg_order_minor', Support::divRound($agreed['gross'], $agreed['count']));
        }

        $created = self::interests($s)->count();
        $converted = self::interests($s)->whereNotNull('converted_at')->count();
        $r->metric('dashboard.interests_created', $created)
            ->metric('dashboard.interest_conversion_percent', $created === 0 ? 0.0 : round($converted * 100 / $created, 1))
            ->metric('dashboard.orders_late', $lateOrders)
            ->metric('dashboard.installations_next_14d', self::installations($s));
        if ($installation['count'] > 0) {
            $r->metric('dashboard.installation_first_visit_percent', (float) $installation['first_visit_percent'])
                ->metric('dashboard.installation_avg_minutes', (int) $installation['avg_minutes']);
        }

        return [
            'metrics' => $r->toArray()['metrics'],
            // Interest → converted → agreement approved → completed, each from its own source (SHR-15).
            'funnel' => [
                ['key' => 'interests', 'value' => $created],
                ['key' => 'converted', 'value' => $converted],
                ['key' => 'agreements', 'value' => (int) ($values['sales.agreements_approved'] ?? 0)],
                ['key' => 'completed', 'value' => (int) ($values['sales.completed'] ?? 0)],
            ],
        ];
    }

    /**
     * @return Builder<Interest>
     */
    private static function interests(ReportScope $s)
    {
        return $s->branchFilter(ScopedQuery::apply(Interest::query(), $s->viewer, [Permission::P60], 'branch_id', null, null), 'interests.branch_id')
            ->whereBetween('interests.created_at', [Support::at($s->start()), Support::at($s->end())]);
    }

    private static function installations(ReportScope $s): int
    {
        $tz = AppointmentBooking::timezone();
        $from = CarbonImmutable::parse(AppointmentBooking::today(), $tz)->startOfDay();
        $to = $from->addDays(14)->endOfDay();
        $app = (string) config('app.timezone');

        return Appointment::query()->whereIn('order_id', Support::orderIds($s->orders()))
            ->where('kind', 'installation')->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [Support::at($from->setTimezone($app)), Support::at($to->setTimezone($app))])->count();
    }

    /**
     * Active orders by the stage they are in now, and how many of them are past that stage's target days.
     *
     * @return array{by_stage: list<array{key: string, label: string, value: int}>, late: int}
     */
    private static function stages(ReportScope $s): array
    {
        $active = (clone $s->orders())->whereNotIn('orders.status', SalesReport::TERMINAL);
        $rows = OrderStage::query()->whereIn('order_id', Support::orderIds($active))->where('status', 'in_progress')
            ->orderBy('position')->limit(self::STAGE_CAP)->get(['id', 'order_id', 'key', 'name', 'position', 'target_days', 'started_at']);

        $now = CarbonImmutable::now();
        $late = 0;
        $groups = [];
        foreach ($rows as $stage) {
            $groups[$stage->key] ??= ['key' => $stage->key, 'label' => (string) $stage->name->get(), 'value' => 0];
            $groups[$stage->key]['value']++;
            if ($stage->target_days !== null && $stage->started_at !== null && $stage->started_at->addDays($stage->target_days)->lt($now)) {
                $late++;
            }
        }

        return ['by_stage' => array_values($groups), 'late' => $late];
    }

    /**
     * Sales booked vs collected per calendar month, the six months ending with the month of the range's end.
     *
     * @return list<array{month: string, from: string, to: string, href: string, sales_minor: int, collected_minor: int}>
     */
    private static function trend(ReportScope $s): array
    {
        $tz = AppointmentBooking::timezone();
        $anchor = CarbonImmutable::parse($s->to, $tz)->startOfMonth();
        $sales = new SalesReport;
        $out = [];
        for ($i = self::TREND_MONTHS - 1; $i >= 0; $i--) {
            $first = $anchor->subMonths($i);
            $month = new ReportScope($s->viewer, $first->toDateString(), $first->endOfMonth()->toDateString(), $s->branchIds, false);
            $out[] = [
                'month' => $first->format('Y-m'), 'from' => $month->from, 'to' => $month->to, 'href' => DashboardLinks::salesMonth($s, $month->from, $month->to),
                'sales_minor' => $sales->agreed($month, SalesReport::firstApproved(Support::at($month->start()), Support::at($month->end())))['gross'],
                'collected_minor' => CollectionMetrics::movements($month->moneyOrders(), $month->start(), $month->end())['collected'],
            ];
        }

        return $out;
    }

    /**
     * Branch comparison rows when the viewer sees several branches and the plan/grant allows comparing; otherwise
     * a state: `single` (one branch in scope), `locked` (plan lacks the capability) or `hidden` (no P-61).
     *
     * @return array{state: string, rows: list<array<string, mixed>>}
     */
    private static function branches(ReportScope $s): array
    {
        if (count($s->visibleBranchIds()) < 2) {
            return ['state' => 'single', 'rows' => []];
        }
        if (! ReportRegistry::canCompare($s->viewer)) {
            return ['state' => app(LicenseCapabilities::class)->allows('branch_comparisons') ? 'hidden' : 'locked', 'rows' => []];
        }

        return ['state' => 'available', 'rows' => array_map(fn (array $row) => $row + ['href' => DashboardLinks::branch((int) $row['branch_id'])], BranchComparison::rows($s))];
    }

    /**
     * Top people by booked value: the assignee of the orders first approved in range (orders list `assignee` filter).
     *
     * @return list<array{id: int, name: string, orders: int, sales_minor: int, href: string}>
     */
    private static function salespeople(ReportScope $s): array
    {
        if (! $s->amountsVisible()) {
            return [];
        }
        $rows = DB::table('agreements')->join('orders', 'orders.id', '=', 'agreements.order_id')
            ->where('agreements.status', 'active')->whereNotNull('orders.assignee_id')
            ->whereIn('agreements.order_id', Support::orderIds($s->moneyOrders())->where('orders.status', '!=', 'cancelled'))
            ->whereIn('agreements.order_id', SalesReport::firstApproved(Support::at($s->start()), Support::at($s->end())))
            ->groupBy('orders.assignee_id')->orderByRaw('SUM(agreements.total_minor) DESC')->orderBy('orders.assignee_id')->limit(self::LEADERS)
            ->selectRaw('orders.assignee_id as u, COUNT(*) as c, SUM(agreements.total_minor) as s')->get();
        $names = self::names($rows->pluck('u')->all());

        return array_values($rows->map(fn ($r) => ['id' => (int) $r->u, 'name' => $names[(int) $r->u] ?? '', 'orders' => (int) $r->c, 'sales_minor' => (int) $r->s, 'href' => DashboardLinks::assignee((int) $r->u)])->all());
    }

    /**
     * Top executors: tasks completed in range, with the open tasks of the same person already past due.
     *
     * @return list<array{id: int, name: string, completed: int, overdue: int}>
     */
    private static function executors(ReportScope $s): array
    {
        $ids = Support::orderIds($s->orders());
        $done = Task::query()->whereIn('order_id', $ids)->where('status', 'completed')->whereNotNull('completed_by')
            ->whereBetween('completed_at', [Support::at($s->start()), Support::at($s->end())])
            ->groupBy('completed_by')->orderByRaw('COUNT(*) DESC')->orderBy('completed_by')->limit(self::LEADERS)
            ->selectRaw('completed_by as u, COUNT(*) as c')->toBase()->get();
        $users = $done->pluck('u')->map(fn ($u) => (int) $u)->all();
        $late = $users === [] ? collect() : Task::query()->whereIn('order_id', $ids)->whereIn('assignee_id', $users)
            ->whereNotIn('status', ['completed', 'cancelled'])->whereNotNull('due_at')->where('due_at', '<', Support::at(CarbonImmutable::now()))
            ->groupBy('assignee_id')->selectRaw('assignee_id as u, COUNT(*) as c')->toBase()->pluck('c', 'u');
        $names = self::names($users);

        return array_values($done->map(fn ($r) => [
            'id' => (int) $r->u, 'name' => $names[(int) $r->u] ?? '', 'completed' => (int) $r->c, 'overdue' => (int) ($late[(int) $r->u] ?? 0),
        ])->all());
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private static function names(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', array_map('intval', $ids))->get(['id', 'name'])->mapWithKeys(fn (User $u) => [$u->id => (string) $u->name])->all();
    }
}
