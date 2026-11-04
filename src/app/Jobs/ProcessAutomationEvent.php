<?php

namespace App\Jobs;

use App\Automation\AutomationEngine;
use App\Support\RequestCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued (Redis) evaluation of one automation event against the rules of its release (ADR-0042). Bounded: 3 attempts
 * with growing backoff and a 60 s timeout; the engine's per-rule run row and per-action idempotency key make a retry
 * finish what is left without repeating what already happened. `automation:tick` re-queues events left unprocessed.
 */
class ProcessAutomationEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public int $eventId)
    {
        $this->afterCommit();
    }

    public function handle(AutomationEngine $engine, RequestCache $cache): void
    {
        $cache->flush();
        $engine->process($this->eventId);
    }
}
