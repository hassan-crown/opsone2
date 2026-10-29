<?php

namespace App\Operations\Backup;

use RuntimeException;

/**
 * `manifest.json` inside the encrypted archive: what was backed up and how to prove it came back unchanged.
 */
final readonly class BackupManifest
{
    public const FORMAT = 1;

    public const ENTRY = 'manifest.json';

    /**
     * @param  array{names: list<string>, batch: int}  $migrations
     * @param  array<string, array{rows: int, sha256: string, data: bool}>  $tables
     * @param  array<string, list<array{path: string, size: int, sha256: string}>>  $files
     * @param  array<string, string>  $entries  archive entry → sha256 (everything but the manifest)
     */
    public function __construct(
        public int $format,
        public string $appVersion,
        public string $createdAt,
        public string $driver,
        public ?string $deploymentId,
        public array $migrations,
        public array $tables,
        public array $files,
        public array $entries,
    ) {}

    public function toJson(): string
    {
        return (string) json_encode([
            'format' => $this->format,
            'app_version' => $this->appVersion,
            'created_at' => $this->createdAt,
            'driver' => $this->driver,
            'deployment_id' => $this->deploymentId,
            'migrations' => $this->migrations,
            'tables' => $this->tables,
            'files' => $this->files,
            'entries' => $this->entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_int($data['format'] ?? null)) {
            throw new RuntimeException('The backup manifest is unreadable.');
        }

        /** @var array{names: list<string>, batch: int} $migrations */
        $migrations = ['names' => array_values(array_map('strval', (array) ($data['migrations']['names'] ?? []))), 'batch' => (int) ($data['migrations']['batch'] ?? 0)];

        /** @var array<string, array{rows: int, sha256: string, data: bool}> $tables */
        $tables = (array) ($data['tables'] ?? []);
        /** @var array<string, list<array{path: string, size: int, sha256: string}>> $files */
        $files = (array) ($data['files'] ?? []);
        /** @var array<string, string> $entries */
        $entries = (array) ($data['entries'] ?? []);

        return new self(
            format: $data['format'],
            appVersion: (string) ($data['app_version'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
            driver: (string) ($data['driver'] ?? ''),
            deploymentId: is_string($data['deployment_id'] ?? null) ? $data['deployment_id'] : null,
            migrations: $migrations,
            tables: $tables,
            files: $files,
            entries: $entries,
        );
    }
}
