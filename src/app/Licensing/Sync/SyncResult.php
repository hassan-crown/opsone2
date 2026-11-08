<?php

namespace App\Licensing\Sync;

/**
 * What one sync run did. `error` is a closed code (LicenseSync::ERROR_*, `http_<status>`, `rejected_<problem>`).
 */
final readonly class SyncResult
{
    private function __construct(
        public bool $ok,
        public bool $installed,
        public ?string $licenseId,
        public ?string $error,
    ) {}

    public static function upToDate(?string $licenseId): self
    {
        return new self(true, false, $licenseId, null);
    }

    public static function installed(string $licenseId): self
    {
        return new self(true, true, $licenseId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, false, null, $error);
    }
}
