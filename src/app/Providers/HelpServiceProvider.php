<?php

namespace App\Providers;

use App\Home\HomeWidgets;
use App\Search\Providers\BranchSearchProvider;
use App\Search\Providers\HelpArticleSearchProvider;
use App\Search\Providers\MemberSearchProvider;
use App\Search\SearchService;
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
            HelpArticleSearchProvider::class,
        ], 'search.providers');

        $this->app->singleton(SearchService::class, fn ($app) => new SearchService($app->tagged('search.providers')));
        $this->app->singleton(HomeWidgets::class);
    }
}
