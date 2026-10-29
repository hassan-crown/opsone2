<?php

namespace App\Operations\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * `/health` (ADR-0028): one word per dependency, never an error message, host, version or count.
 */
final class HealthCheck
{
    /**
     * @return array<string, string> dependency → ok | fail | stale
     */
    public function run(): array
    {
        $checks = ['database' => $this->database()];

        if ($this->usesRedis()) {
            $checks['redis'] = $this->redis();
        }

        $checks['scheduler'] = self::fresh(Heartbeats::age(Heartbeats::SCHEDULER), (int) config('ops.health.scheduler_max_age_seconds', 180));

        if (config('queue.default') !== 'sync') {
            $checks['queue'] = self::fresh(Heartbeats::age(Heartbeats::QUEUE), (int) config('ops.health.queue_max_age_seconds', 300));
        }

        return $checks;
    }

    private function database(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function redis(): string
    {
        try {
            Redis::connection()->ping();

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }

    private function usesRedis(): bool
    {
        return in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
    }

    private static function fresh(?int $age, int $max): string
    {
        return $age !== null && $age <= $max ? 'ok' : 'stale';
    }
}
