<?php

namespace App\Console\Commands;

use App\Licensing\CurrentLicense;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('license:status')]
#[Description('Show the license status of this deployment')]
class LicenseStatusCommand extends Command
{
    public function handle(CurrentLicense $license): int
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

        return self::SUCCESS;
    }
}
