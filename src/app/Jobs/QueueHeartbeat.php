<?php

namespace App\Jobs;

use App\Operations\Health\Heartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Proves a worker is consuming the queue (health check, ADR-0028). No retries: the next minute sends another.
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Heartbeats::beat(Heartbeats::QUEUE);
    }
}
