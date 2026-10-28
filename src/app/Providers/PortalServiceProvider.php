<?php

namespace App\Providers;

use App\Access\Permission;
use App\Http\Middleware\AddPreloadLinkHeaders;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\Portal\HandlePortalInertiaRequests;
use App\Http\Middleware\Portal\SetPortalLocale;
use App\Http\Middleware\Portal\UseCustomerSession;
use App\Models\Customer;
use App\Notifications\NotificationCatalogue;
use App\Portal\CustomerAppointments;
use App\Portal\CustomerCancellations;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Customer workspace (P8 part B, ADR-0022): its own middleware group (`customer`: separate session cookie, CSRF, no
 * staff middleware), routes under `/portal` named `customer.*`, and the staff notifications its requests raise.
 */
final class PortalServiceProvider extends ServiceProvider
{
    public const PREFIX = 'portal';

    public function boot(Router $router): void
    {
        Auth::provider('portal-customers', fn (Application $app) => new EloquentUserProvider($app->make('hash'), Customer::class));

        $router->middlewareGroup('customer', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            UseCustomerSession::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            SetPortalLocale::class,
            HandleAppearance::class,
            HandlePortalInertiaRequests::class,
            AddPreloadLinkHeaders::class,
        ]);

        // Outer bound per address on the sign-in endpoints; the per-identifier caps live in CustomerOtp.
        RateLimiter::for('portal-otp', fn (Request $request) => Limit::perMinute(30)->by('portal-otp:'.$request->ip()));

        NotificationCatalogue::define(CustomerCancellations::NOTIFY_REQUESTED, [
            'mandatory' => false,
            'permission' => Permission::P18,
            'title' => 'notifications.type.portal_cancellation_requested.title',
            'body' => 'notifications.type.portal_cancellation_requested.body',
            'route' => null,
        ]);
        NotificationCatalogue::define(CustomerAppointments::NOTIFY_CHANGE, [
            'mandatory' => false,
            'permission' => Permission::P54,
            'title' => 'notifications.type.portal_appointment_change_requested.title',
            'body' => 'notifications.type.portal_appointment_change_requested.body',
            'route' => null,
        ]);

        if (! $this->app->routesAreCached()) {
            Route::middleware('customer')->prefix(self::PREFIX)->name('customer.')->group(base_path('routes/customer.php'));
        }
    }
}
