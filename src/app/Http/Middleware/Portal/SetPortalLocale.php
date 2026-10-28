<?php

namespace App\Http\Middleware\Portal;

use App\Portal\PortalSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer locale: the customer's own preference → `locale` cookie → APP_LOCALE (the staff user's preference never applies).
 */
final class SetPortalLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.available_locales');
        $locale = config('app.locale');
        foreach ([PortalSession::customer()?->portal_locale, $request->cookie('locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                $locale = $candidate;
                break;
            }
        }
        App::setLocale($locale);

        return $next($request);
    }
}
