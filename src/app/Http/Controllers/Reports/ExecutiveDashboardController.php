<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ExecutiveDashboardRequest;
use App\Models\Branch;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Reports\Dashboard\ExecutiveDashboard;
use App\Reports\ReportScope;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner / general-manager dashboard (ADR-0031): P-60 ∧ P-37 over the viewer's own scope, so a branch manager gets
 * the same page for their branch only. JSON for `Accept: application/json`, Inertia otherwise.
 */
class ExecutiveDashboardController extends Controller
{
    public function __invoke(ExecutiveDashboardRequest $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(ExecutiveDashboard::canView($user), 403);

        $scope = $request->scope();
        $visible = (new ReportScope($user, $scope->from, $scope->to))->visibleBranchIds();
        $payload = [
            'dashboard' => ExecutiveDashboard::build($scope, $request->period()),
            'branches' => array_values(Branch::query()->whereIn('id', $visible)->orderBy('id')->get()
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all()),
            'filters' => ['period' => $request->period(), 'from' => $scope->from, 'to' => $scope->to, 'branch_ids' => $scope->branchIds],
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('reports/executive', $payload);
    }
}
