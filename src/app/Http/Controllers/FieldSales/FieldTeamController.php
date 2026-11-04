<?php

namespace App\Http\Controllers\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\FieldSales\FieldRepAccess;
use App\FieldSales\FieldTeam;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\RepDiscountRequest;
use App\Http\Requests\FieldSales\RepPermissionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «فريقي الميداني» (ADR-0041): the supervisor's follow-up of the reps they manage (P-105 over the rep's branches) and
 * the control of each rep's field permissions and discount limit inside the owner's ceiling. A rep outside the
 * supervisor's branches or region is 404.
 */
class FieldTeamController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless(Access::can($user, Permission::P105), 403);

        return Inertia::render('field-sales/team', ['reps' => FieldTeam::overview($user)]);
    }

    public function show(Request $request, User $rep, FieldRepAccess $access): Response
    {
        $user = $this->user($request);
        abort_unless(FieldRepAccess::canManage($user, $rep), 404);

        return Inertia::render('field-sales/rep', FieldTeam::rep($user, $rep) + [
            'permissions' => $access->state($user, $rep),
            'discount' => FieldRepAccess::discount($user, $rep),
        ]);
    }

    public function permission(RepPermissionRequest $request, User $rep, FieldRepAccess $access): RedirectResponse
    {
        abort_unless(FieldRepAccess::canManage($this->user($request), $rep), 404);
        $access->set($this->user($request), $rep, (string) $request->input('permission'), $request->boolean('enabled'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.team.flash.permission')]);

        return back();
    }

    public function discount(RepDiscountRequest $request, User $rep, FieldRepAccess $access): RedirectResponse
    {
        abort_unless(FieldRepAccess::canManage($this->user($request), $rep), 404);
        $access->setDiscount($this->user($request), $rep, $request->integer('limit_bp'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.team.flash.discount')]);

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
