<?php

namespace App\Console\Commands;

use App\Licensing\Sync\LicenseSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('license:sync {--jitter : Wait a random delay first (the hourly schedule uses this)}')]
#[Description('Pull the latest signed license from the central app and send the heartbeat (ADR-0047)')]
class LicenseSyncCommand extends Command
{
    public function handle(LicenseSync $sync): int
    {
        if (! $sync->configured()) {
            $this->components->warn('License sync is not configured (LICENSE_SYNC_URL, LICENSE_SYNC_SECRET_KEY, LICENSE_DEPLOYMENT_ID). Nothing was sent.');

            return self::FAILURE;
        }

        if ($this->option('jitter')) {
            $max = max(0, (int) config('license.sync.jitter_seconds', 600));
            Sleep::for(random_int(0, $max))->seconds();
        }

        $result = $sync->run();

        if (! $result->ok) {
            $this->components->error("License sync failed: {$result->error}. The license state is unchanged.");

            return self::FAILURE;
        }

        $result->installed
            ? $this->components->info("Installed license {$result->licenseId}.")
            : $this->components->info('License is up to date.');

        return self::SUCCESS;
    }
}
