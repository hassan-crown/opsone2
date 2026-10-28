<?php

namespace App\Http\Middleware;

use App\Site\SiteContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public site's language comes from the URL (`/ar/...`, `/en/...`), never from a cookie or user preference, so
 * every URL has exactly one language (SEO, hreflang). `/` uses the facility's default language.
 */
class SiteLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        App::setLocale(is_string($locale) && in_array($locale, SiteContext::LOCALES, true) ? $locale : app(SiteContext::class)->defaultLocale());

        return $next($request);
    }
}
