<?php

namespace App\Console\Commands;

use App\Operations\Backup\BackupStore;
use App\Operations\Backup\BackupWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ops:backup {--prune : Delete backups older than the retention afterwards}')]
#[Description('Encrypted backup of this deployment: database snapshot, private files and manifest (NFR-12)')]
class OpsBackupCommand extends Command
{
    public function handle(BackupWriter $writer, BackupStore $store): int
    {
        try {
            $file = $writer->run();
        } catch (Throwable $e) {
            report($e);
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Backup %s written (%s bytes, sha256 %s).', $file->name, number_format($file->size), substr($file->sha256, 0, 16)));

        if ($this->option('prune')) {
            $deleted = $store->prune((int) config('ops.backup.retention_days', 30));
            $this->components->info(sprintf('Pruned %d backup(s) older than %d days.', count($deleted), (int) config('ops.backup.retention_days', 30)));
        }

        return self::SUCCESS;
    }
}
