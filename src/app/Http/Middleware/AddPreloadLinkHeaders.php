<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's preload `Link` header, bounded in bytes. Pages with many chunks (the order pages) produced a header that,
 * with the session and XSRF cookies, overflowed the web server's FastCGI header buffer (nginx 502 «upstream sent too
 * big header»). Only whole entries are kept, in Vite's order, up to MAX_BYTES; the HTML still carries every
 * `modulepreload` tag, so nothing is lost but an early hint.
 */
class AddPreloadLinkHeaders
{
    public const MAX_BYTES = 1024;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof IlluminateResponse) {
            return $response;
        }

        $links = [];
        $length = 0;
        foreach (Vite::preloadedAssets() as $url => $attributes) {
            $link = "<{$url}>; ".implode('; ', $attributes);
            $added = strlen($link) + ($links === [] ? 0 : 2);
            if ($length + $added > self::MAX_BYTES) {
                break;
            }
            $links[] = $link;
            $length += $added;
        }
        if ($links !== []) {
            $response->header('Link', implode(', ', $links), false);
        }

        return $response;
    }
}
