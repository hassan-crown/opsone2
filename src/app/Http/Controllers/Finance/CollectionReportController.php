<?php

namespace App\Http\Controllers\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Finance\CollectionReport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ReportCollection (SHR-12, FIN-01): basic report P-60; its money needs P-37 (matrix: «المالي يحتاج P-37»).
 */
class CollectionReportController extends Controller
{
    public function show(Request $request): Response|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        abort_unless(Access::can($actor, Permission::P60) && Access::can($actor, Permission::P37), 403);
        $branch = $request->integer('branch_id') ?: null;
        $report = CollectionReport::build($actor, $branch);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($report);
        }

        return Inertia::render('reports/collection', [
            'report' => $report,
            'branch_id' => $branch,
            'branches' => array_values(Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all()),
        ]);
    }
}
