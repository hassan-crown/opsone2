<?php

namespace App\Licensing\Sync;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Outcome of the last sync attempt (ADR-0047 §6), shown by `license:status`. Diagnostic only: it never feeds the
 * license decision, so the cache is enough (a flush shows "never" until the next hourly run).
 *
 * `error` is a short code from a closed set (see LicenseSync), never a response body or secret.
 */
final readonly class SyncStatus
{
    public function __construct(
        public ?CarbonImmutable $lastSyncAt = null,
        public ?string $lastSyncError = null,
        public ?CarbonImmutable $lastSuccessAt = null,
        public ?int $clockSkewSeconds = null,
    ) {}

    public static function load(Cache $cache, string $key): self
    {
        $data = $cache->get($key);
        if (! is_array($data)) {
            return new self;
        }

        return new self(
            lastSyncAt: self::date($data['last_sync_at'] ?? null),
            lastSyncError: is_string($data['last_sync_error'] ?? null) ? $data['last_sync_error'] : null,
            lastSuccessAt: self::date($data['last_success_at'] ?? null),
            clockSkewSeconds: is_int($data['clock_skew_seconds'] ?? null) ? $data['clock_skew_seconds'] : null,
        );
    }

    public function save(Cache $cache, string $key): void
    {
        $cache->forever($key, [
            'last_sync_at' => $this->lastSyncAt?->toIso8601ZuluString(),
            'last_sync_error' => $this->lastSyncError,
            'last_success_at' => $this->lastSuccessAt?->toIso8601ZuluString(),
            'clock_skew_seconds' => $this->clockSkewSeconds,
        ]);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
