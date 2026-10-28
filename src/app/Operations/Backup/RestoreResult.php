<?php

namespace App\Operations\Backup;

final readonly class RestoreResult
{
    /**
     * @param  list<string>  $mismatches  tables whose restored rows do not hash to the manifest
     */
    public function __construct(
        public int $tables,
        public int $rows,
        public int $files,
        public array $mismatches,
    ) {}

    public function verified(): bool
    {
        return $this->mismatches === [];
    }
}
