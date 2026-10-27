<?php

namespace App\Http\Controllers\Notifications;

use App\Access\Access;
use App\Access\Permission;
use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use App\Notifications\NotificationLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ShellNotifications actions (P-64): mark read, mark all read, and open a notification's link. Only the owner's
 * own notifications are reachable; opening re-checks access on the target (the link never grants it).
 */
class NotificationController extends Controller
{
    public function open(Request $request, UserNotification $notification, NotificationLinks $links): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P64), 403);
        abort_unless($notification->user_id === $user->id, 404);

        $url = $links->resolve($user, $notification);

        $notification->forceFill(['read_at' => $notification->read_at ?? now()])->save();

        if ($url === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('notifications.unavailable')]);

            return redirect()->route('dashboard');
        }

        return redirect($url);
    }

    public function read(Request $request, UserNotification $notification): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P64), 403);
        abort_unless($notification->user_id === $user->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P64), 403);

        UserNotification::query()->where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    }
}
