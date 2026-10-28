<?php

namespace App\Operations\Upgrade;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\DB;

/**
 * After every `migrate` run, remembers the kind of each migration that ran, so an older release (whose code no longer
 * has the file) can still tell an expand-only schema from a contracted one (ADR-0028).
 */
final class RecordMigrationKinds
{
    public function __construct(private readonly MigrationCatalogue $catalogue) {}

    public function handle(MigrationsEnded $event): void
    {
        $database = $event->options['database'] ?? null;
        $db = DB::connection(is_string($database) ? $database : null);
        if ($event->method !== 'up' || ! $db->getSchemaBuilder()->hasTable('ops_migration_kinds')) {
            return;
        }

        $table = (string) (config('database.migrations.table') ?? 'migrations');
        $ran = $db->table($table)->pluck('migration')->map(fn ($m) => (string) $m)->all();
        $recorded = $db->table('ops_migration_kinds')->pluck('migration')->map(fn ($m) => (string) $m)->all();

        $rows = [];
        foreach (array_diff($ran, $recorded) as $name) {
            if ($this->catalogue->has($name)) {
                $rows[] = ['migration' => $name, 'kind' => $this->catalogue->kind($name)->value, 'recorded_at' => now()];
            }
        }
        if ($rows !== []) {
            $db->table('ops_migration_kinds')->insert($rows);
        }
    }
}
