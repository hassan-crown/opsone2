<?php

use App\Http\Middleware\AddPreloadLinkHeaders;
use App\Http\Middleware\EnforceSupportAccess;
use App\Http\Middleware\EnsureAccessIsCurrent;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShareLicenseState;
use App\Site\Domain\TrustedHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'locale']);
        // Proxies come from config/trustedproxy.php; hosts are the app URL (with subdomains) and verified custom domains.
        $middleware->trustHosts(at: fn () => TrustedHosts::patterns(), subdomains: true);
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            SetLocale::class,
            EnsureAccessIsCurrent::class,
            EnforceSupportAccess::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            ShareLicenseState::class,
            AddPreloadLinkHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
