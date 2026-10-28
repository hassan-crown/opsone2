<?php

namespace App\Console\Commands;

use App\Operations\Backup\BackupFile;
use App\Operations\Backup\BackupStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:backup-list {--prune : Delete backups older than the retention (the newest is always kept)}')]
#[Description('List this deployment\'s encrypted backups, newest first')]
class OpsBackupListCommand extends Command
{
    public function handle(BackupStore $store): int
    {
        if ($this->option('prune')) {
            $deleted = $store->prune((int) config('ops.backup.retention_days', 30));
            $this->components->info(sprintf('Pruned %d backup(s).', count($deleted)));
        }

        $this->table(['Name', 'Created (UTC)', 'Size', 'Version'], array_map(fn (BackupFile $f) => [
            $f->name, $f->createdAt->toDateTimeString(), number_format($f->size), $f->appVersion,
        ], $store->all()));

        return self::SUCCESS;
    }
}
