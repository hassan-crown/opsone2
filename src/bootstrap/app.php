<?php

use App\Http\Controllers\Operations\HealthController;
use App\Http\Middleware\AddPreloadLinkHeaders;
use App\Http\Middleware\EnforceSupportAccess;
use App\Http\Middleware\EnsureAccessIsCurrent;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RobotsTag;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShareLicenseState;
use App\Site\Domain\TrustedHosts;
use App\Site\SiteRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Monitoring probe outside the web group: no session or cookies (ADR-0028).
        then: fn () => Route::get('/health', HealthController::class)->middleware('throttle:60,1')->name('health'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'locale']);
        // Proxies come from config/trustedproxy.php; hosts are the app URL (with subdomains) and verified custom domains.
        $middleware->trustHosts(at: fn () => TrustedHosts::patterns(), subdomains: true);
        $middleware->append(SecurityHeaders::class);
        $middleware->append(RobotsTag::class);

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
        // 404 / 410 under the public site and store (`/ar/…`, `/en/…`) use the facility's branded page (ADR-0045).
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            $status = $e->getStatusCode();
            if (! in_array($status, [404, 410], true) || $request->expectsJson() || preg_match('#^(ar|en)(/|$)#', $request->path(), $m) !== 1) {
                return null;
            }
            try {
                return app(SiteRenderer::class)->error($status, $m[1]);
            } catch (Throwable $failure) {
                report($failure);

                return null; // the framework's plain page
            }
        });
    })->create();
