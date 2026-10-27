<?php

namespace App\Licensing;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

class LicensingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LicenseKeyring::class, fn ($app) => LicenseKeyring::make(
            keyId: $this->config('license.key_id'),
            publicKey: $this->config('license.public_key'),
            trustDevelopmentKey: $app->environment(['local', 'testing']),
        ));

        $this->app->bind(LicenseVerifier::class, fn ($app) => new LicenseVerifier(
            keyring: $app->make(LicenseKeyring::class),
            host: parse_url((string) $this->config('app.url'), PHP_URL_HOST) ?: null,
            deploymentId: $this->config('license.deployment_id'),
        ));

        $this->app->bind(LicenseStateStore::class, fn ($app) => new LicenseStateStore(
            cache: $app->make(Cache::class),
            db: $app->make(ConnectionInterface::class),
            cacheKey: (string) $this->config('license.cache_key', 'license.state'),
        ));

        $this->app->scoped(CurrentLicense::class, fn ($app) => new CurrentLicense(
            verifier: $app->make(LicenseVerifier::class),
            store: $app->make(LicenseStateStore::class),
            path: (string) $this->config('license.path', storage_path('app/private/license.json')),
            clockToleranceSeconds: (int) $this->config('license.clock_tolerance_seconds', 300),
            heartbeatSeconds: (int) $this->config('license.heartbeat_seconds', 60),
        ));
    }

    private function config(string $key, mixed $default = null): ?string
    {
        $value = $this->app->make('config')->get($key, $default);

        return is_scalar($value) ? (string) $value : null;
    }
}
