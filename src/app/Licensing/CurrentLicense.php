<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * The license in effect for this deployment (ADR-0004). Resolved once per request/job.
 *
 * A license is trusted only when its file verifies and the clock is believable; otherwise the
 * status is "needs attention" and the last trusted license applies at the monotonic clock.
 */
final class CurrentLicense
{
    private ?LicenseSnapshot $snapshot = null;

    public function __construct(
        private readonly LicenseVerifier $verifier,
        private readonly LicenseStateStore $store,
        private readonly string $path,
        private readonly int $clockToleranceSeconds = 300,
        private readonly int $heartbeatSeconds = 60,
    ) {}

    public function status(): LicenseStatus
    {
        return $this->snapshot()->status;
    }

    public function allows(string $capability): bool
    {
        return $this->snapshot()->allows($capability);
    }

    public function limit(string $key): ?int
    {
        return $this->snapshot()->limit($key);
    }

    public function daysRemaining(): ?int
    {
        return $this->snapshot()->daysRemaining();
    }

    public function acceptsNewWork(): bool
    {
        return $this->snapshot()->acceptsNewWork();
    }

    public function problem(): ?LicenseProblem
    {
        return $this->snapshot()->problem;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function snapshot(): LicenseSnapshot
    {
        return $this->snapshot ??= $this->evaluate();
    }

    /**
     * Re-read the license file, e.g. after installing a new one.
     */
    public function refresh(): LicenseSnapshot
    {
        $this->snapshot = null;

        return $this->snapshot();
    }

    /**
     * The last trusted license, if it still verifies (guards against a tampered state row).
     */
    public function trusted(): ?LicensePayload
    {
        $envelope = $this->store->get()?->envelope;

        if ($envelope === null) {
            return null;
        }

        try {
            return $this->verifier->verify($envelope);
        } catch (LicenseVerificationException) {
            return null;
        }
    }

    private function evaluate(): LicenseSnapshot
    {
        $now = CarbonImmutable::now('UTC');
        $state = $this->store->get();
        $trusted = $this->trusted();
        $lastSeen = $state?->lastSeenAt;
        $monotonic = $lastSeen !== null && $lastSeen->gt($now) ? $lastSeen : $now;

        [$contents, $problem] = $this->read();
        $license = null;

        if ($contents !== null) {
            try {
                $license = $this->verifier->verify($contents);
            } catch (LicenseVerificationException $e) {
                $problem = $e->problem;
            }
        }

        if ($license !== null && $trusted !== null && $license->issuedAt->lt($trusted->issuedAt)) {
            [$license, $problem] = [null, LicenseProblem::Superseded];
        }

        $clockBehind = $lastSeen !== null && $now->lt($lastSeen->subSeconds($this->clockToleranceSeconds));
        $issuedInFuture = $license !== null && $license->issuedAt->gt($now->addSeconds($this->clockToleranceSeconds));

        if ($clockBehind || $issuedInFuture) {
            [$license, $problem] = [null, LicenseProblem::ClockUntrusted];
        }

        if ($license !== null) {
            $snapshot = new LicenseSnapshot($license->statusAt($now), $license, null, $now);
            $this->remember($state, new LicenseState($contents, $snapshot->status, null, $now, $monotonic));

            return $snapshot;
        }

        $snapshot = new LicenseSnapshot(LicenseStatus::NeedsAttention, $trusted, $problem, $monotonic);
        $this->remember($state, new LicenseState($state?->envelope, LicenseStatus::NeedsAttention, $problem, $state?->verifiedAt, $monotonic));

        return $snapshot;
    }

    /**
     * Persist when something changed, or at most once per heartbeat to advance the monotonic clock.
     */
    private function remember(?LicenseState $previous, LicenseState $next): void
    {
        $unchanged = $previous !== null
            && $previous->envelope === $next->envelope
            && $previous->status === $next->status
            && $previous->problem === $next->problem
            && $next->lastSeenAt->lt($previous->lastSeenAt->addSeconds($this->heartbeatSeconds));

        if (! $unchanged) {
            $this->store->put($next);
        }
    }

    /**
     * @return array{0: ?string, 1: ?LicenseProblem}
     */
    private function read(): array
    {
        if (! is_file($this->path)) {
            return [null, LicenseProblem::Missing];
        }

        if (! is_readable($this->path) || filesize($this->path) > LicenseVerifier::MAX_BYTES) {
            return [null, LicenseProblem::Unreadable];
        }

        $contents = file_get_contents($this->path);

        return $contents === false ? [null, LicenseProblem::Unreadable] : [$contents, null];
    }
}
