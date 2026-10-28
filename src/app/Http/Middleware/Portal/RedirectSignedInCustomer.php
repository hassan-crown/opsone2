<?php

namespace App\Http\Middleware\Portal;

use App\Portal\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign-in pages send a customer who is already signed in to their workspace.
 */
final class RedirectSignedInCustomer
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = PortalSession::customer();
        if ($customer !== null && PortalSession::isCurrent($request, $customer)) {
            return redirect()->route('customer.home');
        }

        return $next($request);
    }
}
