<?php

namespace App\Providers;

use App\Site\Domain\DnsTxtResolver;
use App\Site\Domain\SystemDnsTxtResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Public site (P8 part A): the DNS resolver seam and the lead-form throttle.
 */
class SiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DnsTxtResolver::class, SystemDnsTxtResolver::class);
    }

    public function boot(): void
    {
        RateLimiter::for('site-lead', fn (Request $request) => [
            Limit::perHour((int) config('site.lead.per_ip_per_hour'))->by('site-lead-h:'.$request->ip()),
            Limit::perDay((int) config('site.lead.per_ip_per_day'))->by('site-lead-d:'.$request->ip()),
        ]);
    }
}
