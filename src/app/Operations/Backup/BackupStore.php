<?php

namespace App\Operations\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The backup disk (ADR-0028): `{directory}/opsone-{Ymd-His}Z.opsbak` plus a `.json` sidecar. The disk must be private;
 * a public or web-served disk is refused. Pruning keeps the newest backup even when it is older than the retention.
 */
final class BackupStore
{
    private const PATTERN = '/^opsone-(\d{8}-\d{6})Z\.opsbak$/';

    public function __construct(private readonly ?string $diskName = null) {}

    public function disk(): Filesystem
    {
        $name = $this->diskName ?? (string) config('ops.backup.disk');
        $config = (array) config("filesystems.disks.{$name}", []);
        $root = is_string($config['root'] ?? null) ? realpath($config['root']) ?: $config['root'] : null;

        if ($config === [] || $name === 'public' || ($config['visibility'] ?? 'private') === 'public'
            || ($config['serve'] ?? false) === true || isset($config['url'])
            || ($root !== null && str_starts_with($root, public_path()))) {
            throw new RuntimeException("Backups must go to a private disk; [{$name}] is not one.");
        }

        return Storage::disk($name);
    }

    public function put(string $encryptedPath, BackupManifest $manifest, CarbonImmutable $at): BackupFile
    {
        $name = 'opsone-'.$at->format('Ymd-His').'Z.opsbak';
        $path = $this->directory().'/'.$name;
        $disk = $this->disk();

        if ($disk->exists($path)) {
            throw new RuntimeException("A backup named {$name} already exists.");
        }

        $stream = fopen($encryptedPath, 'rb') ?: throw new RuntimeException('Cannot read the encrypted backup.');
        try {
            $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $file = new BackupFile($name, $path, $at, (int) filesize($encryptedPath), (string) hash_file('sha256', $encryptedPath), $manifest->appVersion);
        $disk->put($path.'.json', (string) json_encode([
            'name' => $file->name,
            'created_at' => $at->toIso8601ZuluString(),
            'size' => $file->size,
            'sha256' => $file->sha256,
            'app_version' => $file->appVersion,
            'format' => $manifest->format,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $file;
    }

    /**
     * Newest first.
     *
     * @return list<BackupFile>
     */
    public function all(): array
    {
        $disk = $this->disk();
        $files = [];
        foreach ($disk->files($this->directory()) as $path) {
            $name = basename($path);
            if (preg_match(self::PATTERN, $name, $m) !== 1) {
                continue;
            }
            $sidecar = $this->sidecar($disk, $path);
            $files[] = new BackupFile(
                name: $name,
                path: $path,
                createdAt: CarbonImmutable::createFromFormat('Ymd-His', $m[1], 'UTC') ?: CarbonImmutable::now('UTC'),
                size: (int) ($sidecar['size'] ?? $disk->size($path)),
                sha256: (string) ($sidecar['sha256'] ?? ''),
                appVersion: (string) ($sidecar['app_version'] ?? ''),
            );
        }
        usort($files, fn (BackupFile $a, BackupFile $b) => $b->createdAt <=> $a->createdAt);

        return $files;
    }

    public function latest(): ?BackupFile
    {
        return $this->all()[0] ?? null;
    }

    public function find(string $name): BackupFile
    {
        foreach ($this->all() as $file) {
            if ($file->name === basename($name)) {
                return $file;
            }
        }

        throw new RuntimeException("No backup named {$name} on the backup disk.");
    }

    /**
     * Copies the encrypted file to a local path and checks it against the sidecar checksum.
     */
    public function download(BackupFile $file, string $localPath): void
    {
        $in = $this->disk()->readStream($file->path) ?? throw new RuntimeException('Cannot read the backup.');
        $out = fopen($localPath, 'wb') ?: throw new RuntimeException('Cannot write a local copy.');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($file->sha256 !== '' && ! hash_equals($file->sha256, (string) hash_file('sha256', $localPath))) {
            throw new RuntimeException('The backup does not match its recorded checksum (damaged or replaced).');
        }
    }

    /**
     * Deletes backups older than the retention, always keeping the newest one.
     *
     * @return list<string> names deleted
     */
    public function prune(int $days, ?CarbonImmutable $now = null): array
    {
        $cutoff = ($now ?? CarbonImmutable::now('UTC'))->subDays(max(1, $days));
        $deleted = [];
        foreach (array_slice($this->all(), 1) as $file) {
            if ($file->createdAt->lt($cutoff)) {
                $this->disk()->delete([$file->path, $file->path.'.json']);
                $deleted[] = $file->name;
            }
        }

        return $deleted;
    }

    private function directory(): string
    {
        return trim((string) config('ops.backup.directory', 'opsone'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function sidecar(Filesystem $disk, string $path): array
    {
        try {
            $json = $disk->get($path.'.json');

            return is_string($json) ? (array) json_decode($json, true) : [];
        } catch (Throwable) {
            return [];
        }
    }
}
