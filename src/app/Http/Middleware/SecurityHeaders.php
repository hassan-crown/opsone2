<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline headers set by the app itself, so they hold on every surface (staff panel, public site, store, customer
 * workspace) whatever proxy is in front. The policy only forbids what no page here needs (being framed by another
 * site, a foreign <base>, plugins); it does not restrict scripts or form targets, since the pages use inline
 * bootstrap scripts and checkout hands the buyer to the payment provider. A response that already carries a stricter
 * policy (private file downloads) keeps its own.
 */
final class SecurityHeaders
{
    public const POLICY = "base-uri 'self'; frame-ancestors 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', self::POLICY);
        }
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
