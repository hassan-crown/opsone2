<?php

namespace App\Providers;

use App\Search\Providers\CatalogSearchProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Catalog (domain 02): registers its global-search provider.
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([CatalogSearchProvider::class], 'search.providers');
    }
}
