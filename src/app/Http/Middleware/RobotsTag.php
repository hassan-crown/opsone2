<?php

namespace App\Http\Middleware;

use App\Site\Seo\SiteIndexing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Robots-Tag: noindex, nofollow` on every response that is not the indexable public surface (ADR-0045): the staff
 * panel, the customer workspace, checkout and all of dev/stage. A response that already says something keeps it.
 */
final class RobotsTag
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Robots-Tag') && ! SiteIndexing::indexable($request)) {
            $response->headers->set('X-Robots-Tag', SiteIndexing::NOINDEX);
        }

        return $response;
    }
}
