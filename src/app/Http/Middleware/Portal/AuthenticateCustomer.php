<?php

namespace App\Http\Middleware\Portal;

use App\Portal\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer workspace routes need a signed-in customer (guard `customer`). Unlike `auth:customer` it never switches
 * the default guard, so `$request->user()` stays the staff guard (empty here) and no staff check can ever see a
 * customer. A session whose login was disabled or that was signed out everywhere ends here.
 */
final class AuthenticateCustomer
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = PortalSession::customer();

        if ($customer !== null && ! PortalSession::isCurrent($request, $customer)) {
            PortalSession::end($request);
            $customer = null;
        }

        if ($customer === null) {
            if ($request->expectsJson()) {
                abort(401);
            }
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('customer.login');
        }

        return $next($request);
    }
}
