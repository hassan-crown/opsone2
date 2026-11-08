<?php

namespace App\Console\Commands;

use App\Licensing\CurrentLicense;
use App\Licensing\Sync\LicenseSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('license:status')]
#[Description('Show the license status of this deployment')]
class LicenseStatusCommand extends Command
{
    public function handle(CurrentLicense $license, LicenseSync $sync): int
    {
        $snapshot = $license->refresh();
        $payload = $snapshot->payload;

        $this->components->twoColumnDetail('Status', $snapshot->status->value);
        $this->components->twoColumnDetail('Effective status', $snapshot->effectiveStatus()->value ?? '-');
        $this->components->twoColumnDetail('Problem', $snapshot->problem->value ?? '-');
        $this->components->twoColumnDetail('Plan', $payload->plan ?? '-');
        $this->components->twoColumnDetail('Facility', $payload->facilityId ?? '-');
        $this->components->twoColumnDetail('Capabilities', $payload !== null ? implode(', ', $payload->capabilities) : '-');
        $this->components->twoColumnDetail('Term ends', $payload?->endsAt?->toIso8601String() ?? '-');
        $this->components->twoColumnDetail('Phase ends', $snapshot->phaseEndsAt()?->toIso8601String() ?? '-');
        $this->components->twoColumnDetail('Days remaining', (string) ($snapshot->daysRemaining() ?? '-'));

        $status = $sync->status();
        $this->components->twoColumnDetail('Sync', $sync->configured() ? 'configured' : 'not configured');
        $this->components->twoColumnDetail('Last sync at', $status->lastSyncAt?->toIso8601ZuluString() ?? 'never');
        $this->components->twoColumnDetail('Last sync error', $status->lastSyncAt === null ? '-' : ($status->lastSyncError ?? 'none'));
        $this->components->twoColumnDetail('Last successful sync', $status->lastSuccessAt?->toIso8601ZuluString() ?? 'never');

        return self::SUCCESS;
    }
}
