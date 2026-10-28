<?php

namespace App\Providers;

use App\Agreements\CollectedAmounts;
use App\Agreements\NoCollections;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // What finance collected per order; P6b binds the real ledger (docs/p6-finance-api.md).
        $this->app->bindIf(CollectedAmounts::class, NoCollections::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Own bucket per user, so order creation never shares a bare throttle with other routes.
        RateLimiter::for('orders-create', fn (Request $request) => Limit::perMinute(30)->by('orders-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Shared bucket per user for the other P4 create/apply endpoints (customers, contacts, interests, conversion,
        // order lines, branch transfers, catalog records): generous for people, a ceiling for scripts.
        RateLimiter::for('records-create', fn (Request $request) => Limit::perMinute(60)->by('records-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
