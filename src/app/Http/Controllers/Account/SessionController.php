<?php

namespace App\Http\Controllers\Account;

use App\Access\Access;
use App\Access\Permission;
use App\Account\UserSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\SignOutOtherSessionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My sessions" (P-84): the user's own signed-in devices; sign out one other device or all the others.
 */
class SessionController extends Controller
{
    public function index(Request $request, UserSessions $sessions): Response
    {
        abort_unless(Access::can($request->user(), Permission::P84), 403);

        return Inertia::render('settings/sessions', [
            'supported' => $sessions->supported(),
            'sessions' => $sessions->for($request->user(), $request->session()->getId()),
        ]);
    }

    public function destroyOthers(SignOutOtherSessionsRequest $request, UserSessions $sessions): RedirectResponse
    {
        abort_unless(Access::can($request->user(), Permission::P84), 403);

        $count = $sessions->endOthers($request->user(), $request->session());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.sessions.signed_out_others', ['count' => $count])]);

        return back();
    }

    public function destroy(Request $request, UserSessions $sessions, string $session): RedirectResponse
    {
        abort_unless(Access::can($request->user(), Permission::P84), 403);

        $ended = $sessions->end($request->user(), $session, $request->session()->getId());
        abort_unless($ended, 404);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.sessions.signed_out_one')]);

        return back();
    }
}
