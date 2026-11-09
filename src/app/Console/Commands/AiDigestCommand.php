<?php

namespace App\Console\Commands;

use App\Ai\Insights\DailyDigest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:digest')]
#[Description('Queue the morning AI digest for every owner and manager (no-op when the digest is off)')]
class AiDigestCommand extends Command
{
    public function handle(DailyDigest $digest): int
    {
        if (! $digest->available()) {
            $this->components->info('The AI digest is off (facility switch or license).');

            return self::SUCCESS;
        }

        $this->components->info($digest->dispatchAll().' digest(s) queued.');

        return self::SUCCESS;
    }
}
