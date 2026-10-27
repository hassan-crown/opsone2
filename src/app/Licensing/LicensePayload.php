<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Verified license payload, schema version 1 (ADR-0004).
 */
final readonly class LicensePayload
{
    /** @var list<int> */
    public const array SUPPORTED_SCHEMAS = [1];

    /** @var list<string> */
    public const array LIMIT_KEYS = ['seats', 'branches', 'storage_mb'];

    /**
     * @param  list<string>  $domains
     * @param  list<string>  $capabilities
     * @param  array<string, int>  $limits
     */
    public function __construct(
        public int $schemaVersion,
        public string $licenseId,
        public string $facilityId,
        public string $deploymentId,
        public array $domains,
        public string $plan,
        public array $capabilities,
        public array $limits,
        public ?string $activationId,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
        public int $graceDays,
        public int $windDownDays,
        public int $retentionDays,
        public CarbonImmutable $issuedAt,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $activationId = self::nullableString($data, 'activation_id');
        $startsAt = self::nullableTime($data, 'starts_at');
        $endsAt = self::nullableTime($data, 'ends_at');

        if ($activationId !== null && ($startsAt === null || $endsAt === null)) {
            throw new InvalidArgumentException('An activated license needs starts_at and ends_at.');
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
            throw new InvalidArgumentException('ends_at must be after starts_at.');
        }

        $limits = $data['limits'] ?? null;

        if (! is_array($limits)) {
            throw new InvalidArgumentException('limits must be an object.');
        }

        $parsedLimits = [];

        foreach (self::LIMIT_KEYS as $key) {
            $parsedLimits[$key] = self::count($limits, $key);
        }

        return new self(
            schemaVersion: self::count($data, 'schema_version'),
            licenseId: self::string($data, 'license_id'),
            facilityId: self::string($data, 'facility_id'),
            deploymentId: self::string($data, 'deployment_id'),
            domains: array_map(strtolower(...), self::strings($data, 'domains')),
            plan: self::string($data, 'plan'),
            capabilities: self::strings($data, 'capabilities'),
            limits: $parsedLimits,
            activationId: $activationId,
            startsAt: $startsAt,
            endsAt: $endsAt,
            graceDays: self::count($data, 'grace_days'),
            windDownDays: self::count($data, 'wind_down_days'),
            retentionDays: self::count($data, 'retention_days'),
            issuedAt: self::nullableTime($data, 'issued_at') ?? throw new InvalidArgumentException('issued_at is required.'),
        );
    }

    public function isActivated(): bool
    {
        return $this->activationId !== null && $this->startsAt !== null && $this->endsAt !== null;
    }

    /**
     * The M-19 phase at the given moment.
     */
    public function statusAt(CarbonImmutable $at): LicenseStatus
    {
        if (! $this->isActivated() || $this->endsAt === null || $at->lt($this->startsAt)) {
            return LicenseStatus::Provisioned;
        }

        return match (true) {
            $at->lt($this->endsAt) => LicenseStatus::Active,
            $at->lt($this->graceEndsAt()) => LicenseStatus::Grace,
            $at->lt($this->windDownEndsAt()) => LicenseStatus::WindDown,
            default => LicenseStatus::Expired,
        };
    }

    /**
     * When the phase in effect at the given moment ends (null for an unactivated license).
     */
    public function phaseEndsAt(CarbonImmutable $at): ?CarbonImmutable
    {
        return match ($this->statusAt($at)) {
            LicenseStatus::Provisioned => $this->isActivated() ? $this->startsAt : null,
            LicenseStatus::Active => $this->endsAt,
            LicenseStatus::Grace => $this->graceEndsAt(),
            LicenseStatus::WindDown => $this->windDownEndsAt(),
            default => $this->retentionEndsAt(),
        };
    }

    public function graceEndsAt(): ?CarbonImmutable
    {
        return $this->endsAt?->addDays($this->graceDays);
    }

    public function windDownEndsAt(): ?CarbonImmutable
    {
        return $this->graceEndsAt()?->addDays($this->windDownDays);
    }

    public function retentionEndsAt(): ?CarbonImmutable
    {
        return $this->windDownEndsAt()?->addDays($this->retentionDays);
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$key} must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key);
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    private static function strings(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$key} must be a list.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException("{$key} must contain non-empty strings.");
            }
        }

        /** @var list<non-empty-string> $value */
        return array_values(array_unique($value));
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function count(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$key} must be a non-negative integer.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     */
    private static function nullableTime(array $data, string $key): ?CarbonImmutable
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        // ISO-8601 with an explicit offset, e.g. 2026-10-27T09:00:00Z.
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException("{$key} must be an ISO-8601 timestamp with offset.");
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidArgumentException("{$key} is not a valid timestamp.");
        }
    }
}
