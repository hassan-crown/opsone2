<?php

namespace App\Http\Controllers\Field;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Field\ResolveRemarkRequest;
use App\Installation\InstallationRemarks;
use App\Models\Installation\InstallationRemark;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Installation remarks of an order (ADR-0036), on the delivery remarks' contract (ADR-0035): list them, and resolve
 * each on its own (P-52 over the order).
 */
class InstallationRemarkController extends Controller
{
    /** `{remarks: [row]}` for other clients, like `GET /orders/{order}/delivery-remarks` (P-52 on the order). */
    public function index(Request $request, Order $order): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Access::authorize($user, Permission::P52, $order);

        return response()->json(['remarks' => InstallationRemarks::forOrder($order)]);
    }

    public function resolve(ResolveRemarkRequest $request, InstallationRemark $remark, InstallationRemarks $remarks): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $done = $remarks->resolve($user, $remark, (string) $request->input('resolution'), $request->filled('lock_version') ? $request->integer('lock_version') : null);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['remark' => InstallationRemarks::row($done->refresh()->load(['creator:id,name', 'resolver:id,name', 'followUp:id,name']))]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.resolved')]);

        return back();
    }
}
