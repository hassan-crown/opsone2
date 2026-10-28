<?php

namespace App\Http\Controllers\Reports;

use App\Access\Access;
use App\Access\Contracts\LicenseCapabilities;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\Branch;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Reports\BranchComparison;
use App\Reports\Definitions\Report;
use App\Reports\Export\ReportExports;
use App\Reports\ReportRegistry;
use App\Reports\ReportScope;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Report screens (SHR-12, SHR-13): ReportsHome, one screen per report and the branch comparison. Every number comes
 * from the metrics layer scoped to the viewer (ADR-0026); the same payload feeds the export.
 */
class ReportsController extends Controller
{
    public function index(ReportFilterRequest $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P60), 403);

        $available = ReportRegistry::availableTo($user);
        $scope = $request->scope();
        $overview = isset($available['sales']) ? $available['sales']->build($scope)->toArray() : null;
        $payload = [
            'reports' => array_values(array_map(fn (Report $r) => [
                'key' => $r->key(),
                'url' => $r->key() === 'collection' ? route('reports.collection') : route('reports.show', $r->key()),
                'money' => $r->requires() !== null,
            ], $available)),
            'compare' => $this->compareState($user),
            'overview' => $overview,
            'filters' => $this->filters($scope),
            'branches' => $this->branches($scope),
            'can_export' => Access::can($user, Permission::P62),
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('reports/index', $payload);
    }

    public function show(ReportFilterRequest $request, string $report): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $definition = ReportRegistry::find($report);
        abort_if($definition === null, 404);
        abort_unless(ReportRegistry::canOpen($user, $definition), 403);

        $scope = $request->scope();
        $payload = [
            'report' => $definition->build($scope)->toArray(),
            'filters' => $this->filters($scope),
            'branches' => $this->branches($scope),
            'can_export' => ReportExports::canExport($user, $report),
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('reports/show', $payload);
    }

    public function compare(ReportFilterRequest $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(ReportRegistry::canCompare($user) && Access::can($user, Permission::P60), 403);

        $scope = $request->scope();
        $payload = [
            'report' => BranchComparison::build($scope)->toArray(),
            'filters' => $this->filters($scope),
            'branches' => $this->branches($scope),
            'can_export' => ReportExports::canExport($user, ReportRegistry::COMPARE),
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('reports/compare', $payload);
    }

    /** available (P-61 + capability) | locked (plan lacks `branch_comparisons`, ReportsBasic) | hidden (no grant). */
    private function compareState(User $user): string
    {
        if (ReportRegistry::canCompare($user)) {
            return 'available';
        }

        return app(LicenseCapabilities::class)->allows('branch_comparisons') ? 'hidden' : 'locked';
    }

    /**
     * @return array{from: string, to: string, branch_ids: list<int>}
     */
    private function filters(ReportScope $scope): array
    {
        return ['from' => $scope->from, 'to' => $scope->to, 'branch_ids' => $scope->branchIds];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function branches(ReportScope $scope): array
    {
        return array_values(Branch::query()->whereIn('id', (new ReportScope($scope->viewer, $scope->from, $scope->to))->visibleBranchIds())->orderBy('id')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all());
    }
}
