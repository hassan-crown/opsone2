<?php

namespace App\Providers;

use App\Access\Authorizer;
use App\Agreements\CollectedAmounts;
use App\Agreements\NoCollections;
use App\Models\Facility;
use App\Models\Membership;
use App\Models\User;
use App\Support\RequestCache;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(RequestCache::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Request-lifetime values (ADR-0027) never outlive the request, even where one container serves several
        // (tests), nor a write to what they were read from.
        $flush = function () {
            if ($this->app->resolved(RequestCache::class)) {
                $this->app->make(RequestCache::class)->flush();
            }
        };
        Event::listen(RequestHandled::class, function () use ($flush) {
            $flush();
            // The Gate's access-version cache never carries over to the next request either.
            if ($this->app->resolved(Authorizer::class)) {
                $this->app->make(Authorizer::class)->flush();
            }
        });
        foreach ([Facility::class, Membership::class, User::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }

        // Own bucket per user, so order creation never shares a bare throttle with other routes.
        RateLimiter::for('orders-create', fn (Request $request) => Limit::perMinute(30)->by('orders-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Shared bucket per user for the other P4 create/apply endpoints (customers, contacts, interests, conversion,
        // order lines, branch transfers, catalog records): generous for people, a ceiling for scripts.
        RateLimiter::for('report-exports', fn (Request $request) => Limit::perHour((int) config('reports.export_rate_per_hour', 20))->by('report-exports:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('records-create', fn (Request $request) => Limit::perMinute(60)->by('records-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // A relation read in a loop without eager loading is an N+1 (ADR-0027): refused outside production.
        Model::preventLazyLoading(! app()->isProduction());

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
