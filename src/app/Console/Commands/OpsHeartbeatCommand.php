<?php

namespace App\Console\Commands;

use App\Jobs\QueueHeartbeat;
use App\Operations\Health\Heartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:heartbeat')]
#[Description('Stamp the scheduler heartbeat and queue a queue heartbeat (for /health)')]
class OpsHeartbeatCommand extends Command
{
    public function handle(): int
    {
        Heartbeats::beat(Heartbeats::SCHEDULER);
        QueueHeartbeat::dispatch();

        return self::SUCCESS;
    }
}
