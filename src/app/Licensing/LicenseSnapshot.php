<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * The license as evaluated once: either a trusted license, or "needs attention" carrying the
 * last trusted license evaluated at the monotonic clock (M-19-T05).
 */
final readonly class LicenseSnapshot
{
    public function __construct(
        public LicenseStatus $status,
        public ?LicensePayload $payload,
        public ?LicenseProblem $problem,
        public CarbonImmutable $at,
    ) {}

    /**
     * The phase that governs behaviour: the license's own, or the last trusted one while it needs attention.
     */
    public function effectiveStatus(): ?LicenseStatus
    {
        if ($this->status !== LicenseStatus::NeedsAttention) {
            return $this->status;
        }

        return $this->payload?->statusAt($this->at);
    }

    public function allows(string $capability): bool
    {
        return $this->payload !== null
            && $this->effectiveStatus()?->grantsCapabilities() === true
            && in_array($capability, $this->payload->capabilities, true);
    }

    /**
     * A proven limit, or null when none is proven; callers must then refuse any increase.
     */
    public function limit(string $key): ?int
    {
        if ($this->payload === null || $this->effectiveStatus()?->grantsCapabilities() !== true) {
            return null;
        }

        return $this->payload->limits[$key] ?? null;
    }

    public function acceptsNewWork(): bool
    {
        return $this->payload !== null && $this->effectiveStatus()?->acceptsNewWork() === true;
    }

    /**
     * Days left in the current phase, rounded up; null when no phase end is known.
     */
    public function daysRemaining(): ?int
    {
        $end = $this->phaseEndsAt();

        if ($end === null) {
            return null;
        }

        return max(0, (int) ceil($this->at->diffInSeconds($end, false) / 86400));
    }

    public function phaseEndsAt(): ?CarbonImmutable
    {
        return $this->payload?->phaseEndsAt($this->at);
    }
}
