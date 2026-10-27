<?php

namespace App\Console\Commands;

use App\Licensing\LicenseInstaller;
use App\Licensing\LicenseVerificationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('license:install {path : Path to the signed license file}')]
#[Description('Verify a signed license and install it for this deployment')]
class LicenseInstallCommand extends Command
{
    public function handle(LicenseInstaller $installer): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("License file [{$path}] is not readable.");

            return self::FAILURE;
        }

        try {
            $snapshot = $installer->install((string) file_get_contents($path));
        } catch (LicenseVerificationException $e) {
            $this->components->error("License rejected: {$e->problem->value}. Nothing was installed.");

            return self::FAILURE;
        }

        $this->components->info("License installed. Status: {$snapshot->status->value}.");

        return self::SUCCESS;
    }
}
