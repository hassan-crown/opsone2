<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Resolve the request locale: user preference → `locale` cookie → APP_LOCALE.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $supported = config('app.available_locales');

        foreach ([$request->user()?->locale, $request->cookie('locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return config('app.locale');
    }

    public static function direction(string $locale): string
    {
        return $locale === 'ar' ? 'rtl' : 'ltr';
    }
}
