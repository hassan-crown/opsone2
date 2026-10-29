<?php

namespace App\Operations\Backup;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Licensing\CurrentLicense;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * `ops:backup` (NFR-12, Q-54): one consistent snapshot of this deployment's database plus its private files and a
 * manifest (app version, migrations, row counts, checksums), zipped, encrypted with the backup key and written to
 * the private backup disk with a plaintext sidecar that holds only the encrypted file's size and checksum.
 */
final class BackupWriter
{
    public function __construct(private readonly BackupStore $store) {}

    /**
     * @param  list<string>|null  $fileDisks
     */
    public function run(?string $connection = null, ?array $fileDisks = null, ?BackupKey $key = null, ?CarbonImmutable $at = null): BackupFile
    {
        $key ??= BackupKey::fromConfig();
        $at ??= CarbonImmutable::now('UTC');
        $fileDisks ??= array_values((array) config('ops.backup.file_disks', []));
        $snapshot = DatabaseSnapshot::on($connection);

        $work = Workspace::create();

        try {
            $zipPath = $work->path('backup.zip');
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the backup archive.');
            }

            $entries = [];
            // By reference: the dump records each entry's checksum in $entries.
            $tables = $snapshot->consistently(function () use ($snapshot, $work, $zip, &$entries): array {
                return $this->dumpDatabase($snapshot, $work, $zip, $entries);
            });
            $files = $this->collectFiles($fileDisks, $work, $zip, $entries);

            $manifest = new BackupManifest(
                format: BackupManifest::FORMAT,
                appVersion: (string) config('ops.version'),
                createdAt: $at->toIso8601ZuluString(),
                driver: $snapshot->driver(),
                deploymentId: $this->deploymentId(),
                migrations: $this->migrations($snapshot),
                tables: $tables,
                files: $files,
                entries: $entries,
            );
            $zip->addFromString(BackupManifest::ENTRY, $manifest->toJson());
            if ($zip->close() !== true) {
                throw new RuntimeException('Cannot write the backup archive.');
            }

            $encrypted = $work->path('backup.opsbak');
            BackupCipher::encrypt($zipPath, $encrypted, $key);

            $file = $this->store->put($encrypted, $manifest, $at);
        } finally {
            $work->delete();
        }

        $this->audit(AuditAction::BackupCreated, $snapshot->connection()->getName(), [
            'backup' => $file->name,
            'tables' => count($manifest->tables),
            'rows' => array_sum(array_column($manifest->tables, 'rows')),
            'files' => array_sum(array_map('count', $manifest->files)),
        ]);

        return $file;
    }

    /**
     * @param  array<string, string>  $entries
     * @return array<string, array{rows: int, sha256: string, data: bool}>
     */
    private function dumpDatabase(DatabaseSnapshot $snapshot, Workspace $work, ZipArchive $zip, array &$entries): array
    {
        $skip = (array) config('ops.backup.skip_data', []);
        $tables = [];

        foreach ($snapshot->tables() as $table) {
            $schemaEntry = "schema/{$table}.json";
            $ddl = (string) json_encode($snapshot->ddl($table), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $zip->addFromString($schemaEntry, $ddl);
            $entries[$schemaEntry] = hash('sha256', $ddl);

            $withData = ! in_array($table, $skip, true);
            $tables[$table] = ['rows' => 0, 'sha256' => '', 'data' => $withData];
            if (! $withData) {
                continue;
            }

            $path = $work->path('data/'.$table.'.ndjson');
            $handle = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}.");
            $hash = hash_init('sha256');
            $rows = -1;
            foreach ($snapshot->lines($table) as $line) {
                fwrite($handle, $line);
                hash_update($hash, $line);
                $rows++;
            }
            fclose($handle);

            $sha = hash_final($hash);
            $zip->addFile($path, "data/{$table}.ndjson");
            $entries["data/{$table}.ndjson"] = $sha;
            $tables[$table] = ['rows' => $rows, 'sha256' => $sha, 'data' => true];
        }

        return $tables;
    }

    /**
     * @param  list<string>  $disks
     * @param  array<string, string>  $entries
     * @return array<string, list<array{path: string, size: int, sha256: string}>>
     */
    private function collectFiles(array $disks, Workspace $work, ZipArchive $zip, array &$entries): array
    {
        $result = [];
        foreach ($disks as $name) {
            $disk = Storage::disk($name);
            $result[$name] = [];
            foreach ($disk->allFiles() as $path) {
                $local = self::localPath($disk, $path, $work);
                $sha = (string) hash_file('sha256', $local);
                $entry = "files/{$name}/{$path}";
                $zip->addFile($local, $entry);
                $entries[$entry] = $sha;
                $result[$name][] = ['path' => $path, 'size' => (int) filesize($local), 'sha256' => $sha];
            }
        }

        return $result;
    }

    private static function localPath(Filesystem $disk, string $path, Workspace $work): string
    {
        if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $disk->path($path);
        }

        $local = $work->path('remote/'.Str::random(24));
        $in = $disk->readStream($path) ?? throw new RuntimeException("Cannot read {$path}.");
        $out = fopen($local, 'wb') ?: throw new RuntimeException("Cannot write {$local}.");
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        return $local;
    }

    /**
     * @return array{names: list<string>, batch: int}
     */
    private function migrations(DatabaseSnapshot $snapshot): array
    {
        $table = (string) (config('database.migrations.table') ?? 'migrations');
        $db = $snapshot->connection();
        if (! $db->getSchemaBuilder()->hasTable($table)) {
            return ['names' => [], 'batch' => 0];
        }

        return [
            'names' => array_values(array_map('strval', $db->table($table)->orderBy('id')->pluck('migration')->all())),
            'batch' => (int) $db->table($table)->max('batch'),
        ];
    }

    private function deploymentId(): ?string
    {
        $configured = config('license.deployment_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        try {
            return app(CurrentLicense::class)->trusted()?->deploymentId;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Best effort: the audit trail lives in the default connection; a drill against another connection is not audited.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function audit(AuditAction $action, string $connection, array $facts): void
    {
        if ($connection !== config('database.default') || ! Schema::hasTable('audit_events')) {
            return;
        }

        $db = Schema::getConnection();
        $db->transaction(fn () => Audit::record($action, null, null, after: $facts, source: 'system', label: (string) ($facts['backup'] ?? '')));
    }
}
