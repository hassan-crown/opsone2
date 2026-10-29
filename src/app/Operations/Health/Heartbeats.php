<?php

namespace App\Operations\Health;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler and queue heartbeats (ADR-0028): `ops:heartbeat` runs every minute from the scheduler, stamps the
 * scheduler beat and queues a tiny job that stamps the queue beat when a worker runs it.
 */
final class Heartbeats
{
    public const SCHEDULER = 'ops.heartbeat.scheduler';

    public const QUEUE = 'ops.heartbeat.queue';

    public static function beat(string $key): void
    {
        Cache::forever($key, CarbonImmutable::now('UTC')->getTimestamp());
    }

    public static function age(string $key): ?int
    {
        // The Redis store keeps numbers unserialized and returns them as strings.
        $at = Cache::get($key);

        return is_numeric($at) ? max(0, CarbonImmutable::now('UTC')->getTimestamp() - (int) $at) : null;
    }
}
