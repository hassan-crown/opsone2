<?php

namespace App\Console\Commands;

use App\Licensing\CurrentLicense;
use App\Operations\Backup\BackupKey;
use App\Operations\Backup\BackupRestorer;
use App\Operations\Backup\BackupStore;
use App\Operations\Backup\DatabaseSnapshot;
use App\Operations\Backup\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ops:restore
    {backup : Backup name on the backup disk (see ops:backup-list) or a local .opsbak path}
    {--connection= : Target connection (default: this deployment\'s)}
    {--force : Required: replaces every table of the target database}
    {--confirm= : Non-interactive confirmation: the target database name}
    {--verify-only : Decrypt and verify checksums and compatibility, change nothing}
    {--allow-other-deployment : Accept a backup whose deployment id differs (moving a deployment)}')]
#[Description('Verify and restore an encrypted backup into this deployment (NFR-12)')]
class OpsRestoreCommand extends Command
{
    public function handle(BackupRestorer $restorer, BackupStore $store, CurrentLicense $license): int
    {
        $work = Workspace::create();
        $backup = null;

        try {
            $key = BackupKey::fromConfig();
            $local = $this->fetch($store, (string) $this->argument('backup'), $work);
            $backup = $restorer->inspect($local, $key, $work);

            $connection = is_string($this->option('connection')) ? $this->option('connection') : null;
            $snapshot = DatabaseSnapshot::on($connection);
            $database = (string) $snapshot->connection()->getDatabaseName();

            $missing = $restorer->assertCompatible(
                $backup->manifest,
                $snapshot->driver(),
                $this->deploymentId($license),
                (bool) $this->option('allow-other-deployment'),
            );

            $this->components->info(sprintf(
                'Backup verified: %s, version %s, %d tables, %d rows, %d files.',
                $backup->manifest->createdAt,
                $backup->manifest->appVersion,
                count($backup->manifest->tables),
                array_sum(array_column($backup->manifest->tables, 'rows')),
                array_sum(array_map('count', $backup->manifest->files)),
            ));

            if ($this->option('verify-only')) {
                return self::SUCCESS;
            }

            if (! $this->option('force')) {
                $this->components->error('Restoring replaces every table of ['.$database.']. Re-run with --force.');

                return self::FAILURE;
            }

            if (! $this->confirmed($database)) {
                $this->components->error('Confirmation did not match the database name. Nothing was changed.');

                return self::FAILURE;
            }

            $result = $restorer->restore($backup, $connection);

            if (! $result->verified()) {
                $this->components->error('Restored, but these tables do not match the backup: '.implode(', ', $result->mismatches));

                return self::FAILURE;
            }

            $this->components->info(sprintf('Restored and verified %d tables, %d rows and %d files into [%s].', $result->tables, $result->rows, $result->files, $database));
            if ($missing !== []) {
                $this->components->warn(count($missing).' newer migration(s) are not in the backup: run `php artisan migrate --force` next.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error('Restore refused: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $backup?->close();
            $work->delete();
        }
    }

    private function fetch(BackupStore $store, string $name, Workspace $work): string
    {
        if (str_ends_with($name, '.opsbak') && is_file($name)) {
            return $name;
        }

        $local = $work->path('download.opsbak');
        $store->download($store->find($name), $local);

        return $local;
    }

    private function confirmed(string $database): bool
    {
        $typed = $this->option('confirm');
        if (! is_string($typed)) {
            if (! $this->input->isInteractive()) {
                return false;
            }
            $typed = (string) $this->ask("Type the database name [{$database}] to replace it");
        }

        return hash_equals($database, trim($typed));
    }

    private function deploymentId(CurrentLicense $license): ?string
    {
        $configured = config('license.deployment_id');

        return is_string($configured) && $configured !== '' ? $configured : $license->trusted()?->deploymentId;
    }
}
