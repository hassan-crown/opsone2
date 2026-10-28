<?php

namespace App\Http\Middleware\Portal;

use App\Portal\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before StartSession on customer routes: the session store reads and writes the customer cookie
 * (`<session cookie>_portal`), so a customer request never loads, changes or rotates the staff session and a staff
 * request never sees the customer one (ADR-0022). The guard, CSRF token, flash data and redirects all use this store.
 */
final class UseCustomerSession
{
    public function __construct(private readonly SessionManager $sessions) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $store = $this->sessions->driver();
        $staff = $store->getName();
        $store->setName(PortalSession::cookieName());

        try {
            return $next($request);
        } finally {
            // A long-running worker (or the test kernel) serves the next request with the staff cookie again.
            $store->setName($staff);
        }
    }
}
