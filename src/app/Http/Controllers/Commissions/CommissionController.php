<?php

namespace App\Http\Controllers\Commissions;

use App\Access\Access;
use App\Access\Permission;
use App\Commissions\CommissionPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «المالية: العمولات» (FinCommissions, SAL-12..15) and a member's statement (FinMyCommission). Needs P-94 in some
 * scope; every figure is narrowed to the sales the viewer's grants cover.
 */
class CommissionController extends Controller
{
    public function index(Request $request, CommissionPresenter $presenter): Response|JsonResponse
    {
        $viewer = $this->viewer($request);
        abort_unless(Access::can($viewer, Permission::P94), 403);
        $payload = $presenter->index($viewer);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('finance/commissions', $payload);
    }

    public function member(Request $request, User $member, CommissionPresenter $presenter): Response|JsonResponse
    {
        $viewer = $this->viewer($request);
        abort_unless(Access::can($viewer, Permission::P94), 403);
        $payload = $presenter->member($viewer, $member);
        abort_if($payload === null, 403);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($payload);
        }

        return Inertia::render('finance/commission-member', $payload);
    }

    /**
     * «كشف عمولاتي»: the signed-in member's own statement.
     */
    public function mine(Request $request): RedirectResponse
    {
        return to_route('finance.commissions.member', ['member' => $this->viewer($request)->id]);
    }

    private function viewer(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
