<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * Persisted license state: the last trusted envelope and the monotonic last-seen clock.
 */
final readonly class LicenseState
{
    public function __construct(
        public ?string $envelope,
        public LicenseStatus $status,
        public ?LicenseProblem $problem,
        public ?CarbonImmutable $verifiedAt,
        public CarbonImmutable $lastSeenAt,
    ) {}

    /**
     * @return array{envelope: ?string, status: string, problem: ?string, verified_at: ?string, last_seen_at: string}
     */
    public function toArray(): array
    {
        return [
            'envelope' => $this->envelope,
            'status' => $this->status->value,
            'problem' => $this->problem?->value,
            'verified_at' => $this->verifiedAt?->toIso8601String(),
            'last_seen_at' => $this->lastSeenAt->toIso8601String(),
        ];
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $status = is_string($data['status'] ?? null) ? LicenseStatus::tryFrom($data['status']) : null;
        $lastSeen = $data['last_seen_at'] ?? null;

        if ($status === null || ! is_string($lastSeen)) {
            return null;
        }

        $verifiedAt = $data['verified_at'] ?? null;

        return new self(
            envelope: is_string($data['envelope'] ?? null) ? $data['envelope'] : null,
            status: $status,
            problem: is_string($data['problem'] ?? null) ? LicenseProblem::tryFrom($data['problem']) : null,
            verifiedAt: is_string($verifiedAt) ? CarbonImmutable::parse($verifiedAt)->utc() : null,
            lastSeenAt: CarbonImmutable::parse($lastSeen)->utc(),
        );
    }
}
