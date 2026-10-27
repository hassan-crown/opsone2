<?php

namespace App\Http\Middleware;

use App\Actions\StateMachines\M38SupportAccess\SupportAccessTransition;
use App\Actions\StateMachines\M38SupportAccess\T04ExpireSupportAccess;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\User;
use App\SupportAccess\SupportAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpsOne support sessions (M-38, P-80). For a support identity, on every request:
 * - a grant past its end is expired now (not at the next scheduler run) and the session ends with it;
 * - without a live grant the session ends;
 * - access is read-only: anything but GET/HEAD (sign-out aside) is refused;
 * - the sign-in and every page opened are audited on the grant, so the facility sees what support did.
 */
class EnforceSupportAccess
{
    private const SIGNED_IN_FLAG = 'support_access.signed_in';

    public function __construct(
        private readonly SupportAccess $support,
        private readonly T04ExpireSupportAccess $expire,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_support) {
            return $next($request);
        }

        $this->expire->handleDue($user->id);
        $grant = $this->support->liveGrants($user)[0] ?? null;

        if ($grant === null) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                abort(401, __('support_access.session_ended'));
            }

            return redirect()->route('login')->with('status', __('support_access.session_ended'));
        }

        $read = $request->isMethod('GET') || $request->isMethod('HEAD');
        if (! $read && ! $request->routeIs('logout')) {
            abort(403, __('support_access.read_only'));
        }

        $label = SupportAccessTransition::label($grant);
        DB::transaction(function () use ($request, $user, $grant, $label) {
            if ($request->hasSession() && ! $request->session()->get(self::SIGNED_IN_FLAG)) {
                Audit::record(AuditAction::SupportSignedIn, $grant, $user, after: ['expires_at' => $grant->expires_at], label: $label);
                $request->session()->put(self::SIGNED_IN_FLAG, true);
            }
            if ($request->isMethod('GET')) {
                // Route and path only: query strings can carry search terms or personal data.
                Audit::record(AuditAction::SupportViewed, $grant, $user, after: [
                    'route' => $request->route()?->getName(),
                    'path' => '/'.ltrim($request->path(), '/'),
                ], label: $label);
            }
        });

        return $next($request);
    }
}
