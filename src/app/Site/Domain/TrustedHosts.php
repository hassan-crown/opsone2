<?php

namespace App\Site\Domain;

use App\Models\Site\SiteDomain;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Host names this deployment answers for (outside local and tests): the application URL and its subdomains, plus
 * the facility's verified custom domains. Any other Host header is refused before it can reach a generated link
 * (password reset, signed URLs, canonical tags).
 */
final class TrustedHosts
{
    public const CACHE_KEY = 'site:trusted-hosts';

    /**
     * @return list<string> patterns for Request::setTrustedHosts()
     */
    public static function patterns(): array
    {
        try {
            $hosts = Cache::remember(self::CACHE_KEY, 60, fn () => SiteDomain::query()
                ->where('status', SiteDomain::VERIFIED)->pluck('hostname')->all());
        } catch (Throwable) {
            $hosts = [];
        }

        return array_values(array_map(fn (string $host) => '^'.preg_quote($host, '/').'$', $hosts));
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
