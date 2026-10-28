<?php

namespace App\Operations\Backup;

use App\Audit\AuditAction;
use App\Operations\Upgrade\MigrationCatalogue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * `ops:restore` (NFR-12): decrypts a backup, verifies every checksum and the schema compatibility BEFORE touching
 * anything, then recreates the tables of the target connection, loads the rows, writes the files back and proves the
 * result by recomputing each table's checksum. Only the named connection and disks are written (ADR-0002).
 */
final class BackupRestorer
{
    public function __construct(private readonly MigrationCatalogue $migrations) {}

    /**
     * Opens and fully verifies a backup; nothing is written to the database or the file disks.
     */
    public function inspect(string $encryptedPath, BackupKey $key, Workspace $work): VerifiedBackup
    {
        $zipPath = $work->path('restore.zip');
        BackupCipher::decrypt($encryptedPath, $zipPath, $key);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('The decrypted backup is not a readable archive.');
        }

        $json = $zip->getFromName(BackupManifest::ENTRY);
        if ($json === false) {
            throw new RuntimeException('The backup has no manifest.');
        }
        $manifest = BackupManifest::fromJson($json);
        if ($manifest->format !== BackupManifest::FORMAT) {
            throw new RuntimeException("Unsupported backup format {$manifest->format}.");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name !== BackupManifest::ENTRY && ! isset($manifest->entries[$name])) {
                throw new RuntimeException("Unexpected entry {$name} in the backup.");
            }
        }
        foreach ($manifest->entries as $entry => $sha) {
            $stream = $zip->getStream($entry) ?: throw new RuntimeException("Missing entry {$entry}.");
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            if (! hash_equals($sha, hash_final($hash))) {
                throw new RuntimeException("Checksum mismatch for {$entry}.");
            }
        }

        return new VerifiedBackup($manifest, $zip);
    }

    /**
     * Refuses a backup this code cannot run on: another engine, another deployment, or a newer schema.
     *
     * @return list<string> migrations the code has and the backup lacks (run `migrate` after the restore)
     */
    public function assertCompatible(BackupManifest $manifest, string $driver, ?string $deploymentId, bool $allowOtherDeployment = false): array
    {
        if ($manifest->driver !== $driver) {
            throw new RuntimeException("The backup was taken on {$manifest->driver}; the target connection is {$driver}.");
        }

        if (! $allowOtherDeployment && $manifest->deploymentId !== null && $deploymentId !== null && $manifest->deploymentId !== $deploymentId) {
            throw new RuntimeException("The backup belongs to deployment {$manifest->deploymentId}, not {$deploymentId}.");
        }

        $known = $this->migrations->names();
        $unknown = array_values(array_diff($manifest->migrations['names'], $known));
        if ($unknown !== []) {
            throw new RuntimeException('The backup comes from a newer schema than this code ('.count($unknown).' unknown migration(s), e.g. '.$unknown[0].'). Deploy the matching release first.');
        }

        return array_values(array_diff($known, $manifest->migrations['names']));
    }

    /**
     * @param  array<string, string>  $diskMap  disk in the backup → disk to write (default: same name)
     */
    public function restore(VerifiedBackup $backup, ?string $connection = null, array $diskMap = []): RestoreResult
    {
        $manifest = $backup->manifest;
        $snapshot = DatabaseSnapshot::on($connection);

        $snapshot->dropAll();
        $statements = [];
        foreach (array_keys($manifest->tables) as $table) {
            $statements = [...$statements, ...$backup->json("schema/{$table}.json")];
        }
        $snapshot->create(array_map('strval', $statements));

        $rows = 0;
        foreach ($manifest->tables as $table => $meta) {
            if ($meta['data']) {
                $rows += $snapshot->load($table, $backup->lines("data/{$table}.ndjson"));
            }
        }

        $mismatches = [];
        foreach ($manifest->tables as $table => $meta) {
            if (! $meta['data']) {
                continue;
            }
            $actual = $snapshot->checksum($table);
            if ($actual['rows'] !== $meta['rows'] || $actual['sha256'] !== $meta['sha256']) {
                $mismatches[] = $table;
            }
        }

        $files = 0;
        foreach ($manifest->files as $diskName => $list) {
            $disk = Storage::disk($diskMap[$diskName] ?? $diskName);
            foreach ($list as $file) {
                if (str_contains('/'.$file['path'].'/', '/../') || str_starts_with($file['path'], '/')) {
                    throw new RuntimeException("Refusing to write outside the disk: {$file['path']}.");
                }
                $stream = $backup->stream("files/{$diskName}/{$file['path']}");
                $disk->writeStream($file['path'], $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $files++;
            }
        }

        $result = new RestoreResult(count($manifest->tables), $rows, $files, $mismatches);

        BackupWriter::audit(AuditAction::BackupRestored, $snapshot->connection()->getName(), [
            'backup_created_at' => $manifest->createdAt,
            'backup_version' => $manifest->appVersion,
            'tables' => $result->tables,
            'rows' => $result->rows,
            'files' => $result->files,
            'verified' => $result->verified(),
        ]);

        return $result;
    }
}
