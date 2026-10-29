<?php

namespace App\Operations\Upgrade;

use App\Licensing\CurrentLicense;
use App\Operations\Backup\BackupStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * `ops:preflight` (NFR-15, SUB-13): decides whether THIS code may run against THIS database right now.
 *
 * - schema: migrations the database ran that the code does not know must all be recorded as expand (a rolled-back
 *   release on an expanded schema is fine; on a contracted one it is not).
 * - pending: every pending migration carries a marker; an "expand" that drops/renames is refused; a contract needs
 *   maintenance mode. A fresh database (nothing ran yet) passes.
 * - queue: the listed queues are empty (jobs serialized by the old code must not meet the new code mid-flight).
 * - backup: the newest backup is within the RPO before any pending migration runs.
 */
final class Preflight
{
    public function __construct(
        private readonly MigrationCatalogue $catalogue,
        private readonly Application $app,
        private readonly BackupStore $backups,
        private readonly CurrentLicense $license,
    ) {}

    /**
     * @return list<PreflightCheck>
     */
    public function run(bool $skipBackupCheck = false): array
    {
        $table = (string) (config('database.migrations.table') ?? 'migrations');
        $ran = Schema::hasTable($table) ? array_values(DB::table($table)->orderBy('id')->pluck('migration')->map(fn ($m) => (string) $m)->all()) : [];
        $pending = array_values(array_diff($this->catalogue->names(), $ran));

        return [
            $this->schema($ran),
            $this->pending($ran, $pending),
            $this->maintenance($pending),
            $this->queue(),
            $this->backup($pending, $skipBackupCheck),
            $this->licenseInfo(),
        ];
    }

    /**
     * @param  list<string>  $ran
     */
    private function schema(array $ran): PreflightCheck
    {
        $unknown = array_values(array_filter($ran, fn (string $m) => ! $this->catalogue->has($m)));
        if ($unknown === []) {
            return PreflightCheck::pass('schema', 'The database schema matches this release or is older.');
        }

        $kinds = Schema::hasTable('ops_migration_kinds')
            ? DB::table('ops_migration_kinds')->whereIn('migration', $unknown)->pluck('kind', 'migration')->all()
            : [];
        $blocking = array_values(array_filter($unknown, fn (string $m) => ($kinds[$m] ?? null) !== MigrationKind::Expand->value));

        if ($blocking === []) {
            return PreflightCheck::warn('schema', count($unknown).' newer expand-only migration(s) ran here; this older release stays compatible.');
        }

        return PreflightCheck::fail('schema', 'The database has newer contract or unmarked migrations this code does not know ('.implode(', ', array_slice($blocking, 0, 3)).'). Deploy the newer release or restore a matching backup.');
    }

    /**
     * @param  list<string>  $ran
     * @param  list<string>  $pending
     */
    private function pending(array $ran, array $pending): PreflightCheck
    {
        if ($pending === []) {
            return PreflightCheck::pass('migrations', 'No pending migrations.');
        }
        if ($ran === []) {
            return PreflightCheck::pass('migrations', 'Fresh database: '.count($pending).' migration(s) will create the schema.');
        }

        $problems = [];
        foreach ($pending as $name) {
            $kind = $this->catalogue->kind($name);
            if ($kind === MigrationKind::Unmarked) {
                $problems[] = "{$name} has no #[Expand] or #[Contract] marker";
            } elseif ($kind === MigrationKind::Expand && $this->catalogue->destructive($name)) {
                $problems[] = "{$name} is marked expand but drops, renames or changes columns";
            }
        }

        if ($problems !== []) {
            return PreflightCheck::fail('migrations', implode('; ', $problems).'.');
        }

        $contracts = count(array_filter($pending, fn (string $m) => $this->catalogue->kind($m) === MigrationKind::Contract));

        return PreflightCheck::pass('migrations', count($pending).' pending migration(s), '.$contracts.' contract.');
    }

    /**
     * @param  list<string>  $pending
     */
    private function maintenance(array $pending): PreflightCheck
    {
        $down = $this->app->isDownForMaintenance();
        $contract = array_filter($pending, fn (string $m) => $this->catalogue->kind($m) === MigrationKind::Contract) !== [];

        if ($contract && ! $down) {
            return PreflightCheck::fail('maintenance', 'Contract migrations are pending: run `php artisan down` first (after the queue has drained).');
        }

        return $down
            ? PreflightCheck::warn('maintenance', 'The application is in maintenance mode; bring it up with `php artisan up` after migrating.')
            : PreflightCheck::pass('maintenance', 'Expand-only upgrade: no maintenance window needed.');
    }

    private function queue(): PreflightCheck
    {
        $waiting = [];
        foreach ((array) config('ops.preflight.queues', ['default']) as $queue) {
            try {
                $size = Queue::size((string) $queue);
            } catch (Throwable) {
                return PreflightCheck::fail('queue', 'The queue connection is not reachable.');
            }
            if ($size > 0) {
                $waiting[] = "{$queue}: {$size}";
            }
        }

        return $waiting === []
            ? PreflightCheck::pass('queue', 'Queues are drained.')
            : PreflightCheck::fail('queue', 'Jobs are still waiting ('.implode(', ', $waiting).'). Let the worker drain them before deploying.');
    }

    /**
     * @param  list<string>  $pending
     */
    private function backup(array $pending, bool $skip): PreflightCheck
    {
        try {
            $latest = $this->backups->latest();
        } catch (Throwable) {
            $latest = null;
        }

        $maxHours = (int) config('ops.preflight.max_backup_age_hours', 24);
        $fresh = $latest !== null && $latest->createdAt->gte(CarbonImmutable::now('UTC')->subHours($maxHours));

        if ($fresh) {
            return PreflightCheck::pass('backup', "Latest backup {$latest->name}.");
        }
        if ($pending === [] || $skip) {
            return PreflightCheck::warn('backup', "No backup newer than {$maxHours}h.");
        }

        return PreflightCheck::fail('backup', "Pending migrations and no backup newer than {$maxHours}h: run `php artisan ops:backup` first.");
    }

    private function licenseInfo(): PreflightCheck
    {
        return PreflightCheck::pass('license', 'License status: '.$this->license->status()->value.'.');
    }
}
