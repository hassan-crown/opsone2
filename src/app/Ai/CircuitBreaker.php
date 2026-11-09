<?php

namespace App\Ai;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Stops calling the provider after N consecutive failures for a cool-down (plan §3.4: 5 failures, 5 minutes).
 * Any answer from the provider — even a refusal — closes the circuit again. State lives in the cache (Redis in
 * deployments), shared by web and queue workers.
 */
final class CircuitBreaker
{
    private const FAILURES = 'ai:circuit:failures';

    private const OPEN_UNTIL = 'ai:circuit:open_until';

    public function isOpen(): bool
    {
        $until = $this->store()->get(self::OPEN_UNTIL);

        return is_int($until) && $until > now()->getTimestamp();
    }

    public function recordSuccess(): void
    {
        $this->store()->forget(self::FAILURES);
        $this->store()->forget(self::OPEN_UNTIL);
    }

    public function recordFailure(): void
    {
        $cooldown = max(1, (int) config('ai.circuit_breaker.cooldown_seconds', 300));
        // Atomic across workers: add() creates the counter once (with its expiry), increment() never loses a failure.
        $this->store()->add(self::FAILURES, 0, $cooldown * 4);
        $failures = (int) $this->store()->increment(self::FAILURES);

        if ($failures >= max(1, (int) config('ai.circuit_breaker.failures', 5))) {
            $this->store()->put(self::OPEN_UNTIL, now()->getTimestamp() + $cooldown, $cooldown);
            $this->store()->forget(self::FAILURES);
        }
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
