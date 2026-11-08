<?php

namespace App\Console\Commands;

use App\Licensing\Sync\SyncKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Generates this deployment's sync keypair (ADR-0047 §3). The secret key is written straight into `.env` and never
 * printed; only the public key is shown, for registration on the deployment in the central app.
 */
#[Signature('license:sync-keygen {--force : Replace an existing sync key (rotation: register the new public key first)}')]
#[Description('Generate the license sync keypair: secret into .env, public key printed for the central app')]
class LicenseSyncKeygenCommand extends Command
{
    public function handle(): int
    {
        $existing = SyncKey::fromBase64(is_string($value = config('license.sync.secret_key')) ? $value : null);

        if ($existing !== null && ! $this->option('force')) {
            $this->components->warn('A sync key already exists; it was kept. Use --force to replace it.');
            $this->components->twoColumnDetail('Public key', $existing->publicBase64());

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            $this->components->error("Cannot write the environment file [{$path}]. Nothing was changed.");

            return self::FAILURE;
        }

        $key = SyncKey::generate();
        $contents = (string) file_get_contents($path);
        $line = 'LICENSE_SYNC_SECRET_KEY='.$key->secretBase64();
        $updated = preg_match('/^LICENSE_SYNC_SECRET_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^LICENSE_SYNC_SECRET_KEY=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $contents, 1)
            : rtrim($contents, "\n")."\n".$line."\n";

        if (file_put_contents($path, $updated) === false) {
            $this->components->error('Cannot write the environment file. Nothing was changed.');

            return self::FAILURE;
        }

        config(['license.sync.secret_key' => $key->secretBase64()]);

        $this->components->info('Sync secret key written to .env (LICENSE_SYNC_SECRET_KEY). It is not shown.');
        $this->components->twoColumnDetail('Public key', $key->publicBase64());
        $this->line('  Register this public key on the deployment in the central app, then run `php artisan config:cache` if config is cached.');

        return self::SUCCESS;
    }
}
