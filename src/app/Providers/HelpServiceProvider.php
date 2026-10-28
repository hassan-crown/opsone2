<?php

namespace App\Providers;

use App\Home\HomeWidgets;
use App\Search\Providers\BranchSearchProvider;
use App\Search\Providers\CustomerSearchProvider;
use App\Search\Providers\HelpArticleSearchProvider;
use App\Search\Providers\MemberSearchProvider;
use App\Search\SearchService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Shared services of part C: global search providers and the work-center widget registry.
 * Other domains add `$this->app->tag(MyProvider::class, 'search.providers')` in their own provider.
 */
class HelpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([
            MemberSearchProvider::class,
            BranchSearchProvider::class,
            CustomerSearchProvider::class,
            HelpArticleSearchProvider::class,
        ], 'search.providers');

        $this->app->singleton(SearchService::class, fn ($app) => new SearchService($app->tagged('search.providers')));
        $this->app->singleton(HomeWidgets::class);
    }

    public function boot(): void
    {
        // Named, per-feature buckets: a bare `throttle:N,M` keys on the user alone, so unrelated routes would
        // share (and drain) one counter.
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('support-request', fn (Request $request) => Limit::perMinute(5)->by('support-request:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
