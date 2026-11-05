<?php

namespace App\Http\Middleware;

use App\Marketing\Attribution\Attribution;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes where the visitor came from on GET pages of the public site and store (ADR-0045). Never on a draft preview.
 */
final class CaptureAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        $name = (string) $request->route()?->getName();
        if ($request->isMethod('GET') && ! $request->expectsJson() && ! in_array($name, ['site.sitemap', 'site.robots', 'site.media'], true)) {
            Attribution::capture($request);
        }

        return $next($request);
    }
}
