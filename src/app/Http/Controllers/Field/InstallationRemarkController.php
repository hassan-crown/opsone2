<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Http\Requests\Field\ResolveRemarkRequest;
use App\Installation\InstallationRemarks;
use App\Models\Installation\InstallationRemark;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The order owner settles a blocking installation remark from the order page (P-52 over the order, ADR-0036).
 */
class InstallationRemarkController extends Controller
{
    public function resolve(ResolveRemarkRequest $request, InstallationRemark $remark, InstallationRemarks $remarks): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $remarks->resolve($user, $remark, (string) $request->input('resolution'), $request->filled('lock_version') ? $request->integer('lock_version') : null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('installation.flash.resolved')]);

        return back();
    }
}
