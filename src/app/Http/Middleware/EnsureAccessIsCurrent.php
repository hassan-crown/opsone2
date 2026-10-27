<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session when the member was disabled or their access changed since the session started
 * (access_version stamp, see App\Access\SessionRevoker). Applies to every web request, including passkey logins.
 */
class EnsureAccessIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $current = User::query()->whereKey($user->id)->value('access_version');
        $disabled = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Disabled->value)
            ->exists();

        $stamp = $request->session()->get('access_version');
        if ($stamp === null && $current !== null && ! $disabled) {
            $request->session()->put('access_version', (int) $current);

            return $next($request);
        }

        if ($current === null || $disabled || (int) $stamp !== (int) $current) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                abort(401, __('access.session_revoked'));
            }

            return redirect()->route('login')->with('status', __('access.session_revoked'));
        }

        return $next($request);
    }
}
