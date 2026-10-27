<?php

namespace App\Jobs;

use App\Notifications\OutboxDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued (Redis) delivery of one outbox message. Retries are driven by the outbox row itself (backoff and
 * attempt limit live in OutboxDeliverer), so the queue's own retry is off.
 */
class DeliverOutboxMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $outboxId)
    {
        $this->afterCommit();
    }

    public function handle(OutboxDeliverer $deliverer): void
    {
        $deliverer->deliver($this->outboxId);
    }
}
