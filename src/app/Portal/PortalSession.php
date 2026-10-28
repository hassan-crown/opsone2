<?php

namespace App\Portal;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Customer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The customer's signed-in session (guard `customer`, ADR-0022). Signing in rotates the session id and the CSRF token
 * (no fixation), stamps the session with the account's `portal_session_version`, and is audited. «Sign out everywhere»
 * bumps the version, so every other session of the customer ends on its next request (AuthenticateCustomer).
 */
final class PortalSession
{
    public const GUARD = 'customer';

    public const STAMP = 'portal_session_version';

    public const SOURCE = 'customer';

    public static function cookieName(): string
    {
        return config('session.cookie').'_portal';
    }

    public static function guard(): StatefulGuard
    {
        return Auth::guard(self::GUARD);
    }

    public static function customer(): ?Customer
    {
        // Static analysis types every guard's user as the staff model; this guard's provider holds customers only.
        /** @var Authenticatable|null $customer */
        $customer = self::guard()->user();

        return $customer instanceof Customer ? $customer : null;
    }

    public static function signIn(Request $request, Customer $customer, string $channel): void
    {
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();

        DB::transaction(function () use ($customer, $channel, $request) {
            $first = $customer->portal_activated_at === null;
            $customer->forceFill([
                'portal_activated_at' => $customer->portal_activated_at ?? now(),
                'portal_last_login_at' => now(),
                'email_verified_at' => $channel === 'email' ? ($customer->email_verified_at ?? now()) : $customer->email_verified_at,
            ])->save();
            Audit::record(AuditAction::PortalSignedIn, $customer, null, after: [
                'channel' => $channel, 'first' => $first, 'ip' => $request->ip(),
            ], source: self::SOURCE, label: $customer->name);
        });

        self::guard()->login($customer);
        $request->session()->put(self::STAMP, $customer->portal_session_version);
    }

    public static function signOut(Request $request, bool $everywhere = false): void
    {
        $customer = self::customer();
        if ($customer !== null) {
            DB::transaction(function () use ($customer, $everywhere) {
                if ($everywhere) {
                    Customer::query()->whereKey($customer->id)->increment('portal_session_version');
                }
                Audit::record($everywhere ? AuditAction::PortalSessionsEnded : AuditAction::PortalSignedOut, $customer, null,
                    source: self::SOURCE, label: $customer->name);
            });
        }

        self::end($request);
    }

    /**
     * Ends the current customer session without touching any other (or the staff session).
     */
    public static function end(Request $request): void
    {
        self::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Is this session still valid for the account (login still enabled, not signed out everywhere)?
     */
    public static function isCurrent(Request $request, Customer $customer): bool
    {
        $fresh = Customer::query()->whereKey($customer->id)->first(['id', 'portal_login_enabled', 'portal_session_version']);

        return $fresh !== null && $fresh->portal_login_enabled
            && (int) $request->session()->get(self::STAMP) === $fresh->portal_session_version;
    }
}
