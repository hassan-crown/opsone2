<?php

namespace App\Console\Commands;

use App\Operations\Backup\BackupKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:backup-key')]
#[Description('Print a new random OPS_BACKUP_KEY (never written anywhere by this command)')]
class OpsBackupKeyCommand extends Command
{
    public function handle(): int
    {
        $this->line('OPS_BACKUP_KEY=base64:'.BackupKey::generate());
        $this->components->warn('Store it in the deployment secrets AND outside the server: without it no backup can be restored.');

        return self::SUCCESS;
    }
}
