<?php

namespace App\Operations\Backup;

use Carbon\CarbonImmutable;

/**
 * One encrypted backup on the backup disk, described by its plaintext sidecar (no secrets in it).
 */
final readonly class BackupFile
{
    public function __construct(
        public string $name,
        public string $path,
        public CarbonImmutable $createdAt,
        public int $size,
        public string $sha256,
        public string $appVersion,
    ) {}
}
