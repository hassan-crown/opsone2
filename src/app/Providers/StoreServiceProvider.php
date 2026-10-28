<?php

namespace App\Providers;

use App\Finance\Gateway\GatewayOutcomes;
use App\Finance\Gateway\GatewayRegistry;
use App\Finance\Gateway\Moyasar\MoyasarGateway;
use App\Http\Middleware\SiteLocale;
use App\Models\Store\StoreLink;
use App\Notifications\NotificationTargets;
use App\Store\StoreCatalogue;
use App\Store\StoreCheckouts;
use App\Store\StoreGate;
use App\Store\StoreGatewayOutcomes;
use App\Store\StoreNotices;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Ready-goods store (P8 part C, domain 07, ADR-0023): public store and checkout under `/{ar|en}/store` in the customer
 * middleware group (the guest cart and the checkout code live in the customer session), staff coupons / links / store
 * settings under `/store-admin`, the Moyasar gateway (Q-45) and the reservation expiry service. Registered before the
 * public site so `/{locale}/store` is never taken by the site's page route.
 */
final class StoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GatewayOutcomes::class, StoreGatewayOutcomes::class);
        $this->callAfterResolving(GatewayRegistry::class, fn (GatewayRegistry $registry) => $registry->add($this->app->make(MoyasarGateway::class)));
    }

    public function boot(): void
    {
        StoreNotices::define();
        RateLimiter::for('store-otp', fn (Request $request) => Limit::perMinute(20)->by('store-otp:'.$request->ip()));
        RateLimiter::for('store-checkout', fn (Request $request) => Limit::perMinute(20)->by('store-checkout:'.$request->ip()));

        // The site's ready-goods section shows the store's products (only while the store sells).
        View::composer('site.sections.ready_goods', function ($view) {
            $open = $this->app->make(StoreGate::class)->open();
            $view->with('storeOpen', $open)
                ->with('goods', $open ? $this->app->make(StoreCatalogue::class)->products(app()->getLocale(), 8) : []);
        });

        $this->app->make(NotificationTargets::class)->register(
            (new StoreLink)->getMorphClass(),
            fn (int $id) => StoreLink::query()->find($id),
            fn () => route('store.admin.links.index'),
        );

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('store:expire-checkouts')->everyMinute()->withoutOverlapping();
        });

        if (! $this->app->routesAreCached()) {
            Route::group([], base_path('routes/store.php'));
        }
    }

    /**
     * Used by routes/store.php.
     *
     * @return list<string|class-string>
     */
    public static function publicMiddleware(): array
    {
        return ['customer', SiteLocale::class];
    }

    public static function checkouts(): StoreCheckouts
    {
        return app(StoreCheckouts::class);
    }
}
