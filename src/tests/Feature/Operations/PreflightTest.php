<?php

use App\Operations\Backup\BackupKey;
use App\Operations\Backup\BackupWriter;
use App\Operations\Upgrade\MigrationCatalogue;
use App\Operations\Upgrade\MigrationKind;
use App\Operations\Upgrade\Preflight;
use App\Operations\Upgrade\PreflightCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
| Upgrade safety (NFR-15, SUB-13, ADR-0028): ops:preflight refuses an incompatible code/schema combination, unmarked or
| mis-marked migrations, a busy queue, a contract without maintenance mode, and migrating without a fresh backup.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->extra = storage_path('framework/testing/preflight-'.getmypid().'-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->extra);
    config([
        'filesystems.disks.backups_test' => ['driver' => 'local', 'root' => $this->extra.'/backups', 'throw' => true],
        'ops.backup.disk' => 'backups_test',
        'ops.backup.key' => BackupKey::generate(),
    ]);
    useCatalogue([database_path('migrations'), $this->extra]);
});

afterEach(function () {
    app()->maintenanceMode()->deactivate();
    File::deleteDirectory($this->extra);
});

/**
 * @param  list<string>  $paths
 */
function useCatalogue(array $paths): void
{
    app()->instance(MigrationCatalogue::class, new MigrationCatalogue(app('migrator'), $paths));
    app()->forgetInstance(Preflight::class);
}

function pendingMigration(string $dir, string $name, string $marker, string $up): void
{
    $attribute = $marker === '' ? '' : "#[\\App\\Operations\\Upgrade\\{$marker}] ";
    file_put_contents("{$dir}/{$name}.php", <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\Schema;

        return new {$attribute}class extends Migration
        {
            public function up(): void
            {
                {$up}
            }

            public function down(): void
            {
                Schema::dropIfExists('anything');
            }
        };
        PHP);
}

/**
 * @return array<string, PreflightCheck>
 */
function preflight(bool $skipBackup = false): array
{
    $checks = [];
    foreach (app(Preflight::class)->run($skipBackup) as $check) {
        $checks[$check->name] = $check;
    }

    return $checks;
}

function freshBackup(): void
{
    app(BackupWriter::class)->run(null, []);
}

it('passes when code and schema match and the queue is empty', function () {
    $this->artisan('ops:preflight', ['--skip-backup-check' => true])->expectsOutputToContain('Preflight passed')->assertSuccessful();

    $checks = preflight();
    expect($checks['schema']->result)->toBe('pass')
        ->and($checks['migrations']->result)->toBe('pass')
        ->and($checks['backup']->result)->toBe('warn');
});

it('records the kind of every migration that ran', function () {
    expect(DB::table('ops_migration_kinds')->where('migration', '2027_03_01_100000_create_operations_tables')->value('kind'))->toBe('expand')
        ->and(DB::table('ops_migration_kinds')->count())->toBe(DB::table('migrations')->count());
});

it('refuses unmarked and mis-marked pending migrations', function () {
    pendingMigration($this->extra, '2099_01_01_000000_unmarked', '', "Schema::table('users', fn (Blueprint \$t) => \$t->string('nickname')->nullable());");
    expect(preflight()['migrations']->result)->toBe('fail')
        ->and(preflight()['migrations']->message)->toContain('no #[Expand] or #[Contract] marker');

    File::cleanDirectory($this->extra);
    pendingMigration($this->extra, '2099_01_01_000001_sneaky', 'Expand', "Schema::table('users', fn (Blueprint \$t) => \$t->dropColumn('locale'));");
    useCatalogue([database_path('migrations'), $this->extra]);
    expect(preflight()['migrations']->message)->toContain('marked expand but drops');

    $this->artisan('ops:preflight', ['--json' => true])->assertFailed();
});

it('runs an expand-only upgrade without downtime once a fresh backup exists', function () {
    pendingMigration($this->extra, '2099_01_01_000000_add_nickname', 'Expand', "Schema::table('users', fn (Blueprint \$t) => \$t->string('nickname')->nullable());");

    expect(preflight()['backup']->result)->toBe('fail');

    freshBackup();
    $checks = preflight();
    expect($checks['migrations']->result)->toBe('pass')
        ->and($checks['maintenance']->result)->toBe('pass')
        ->and($checks['backup']->result)->toBe('pass')
        ->and(array_filter($checks, fn (PreflightCheck $c) => $c->failed()))->toBe([]);
});

it('requires maintenance mode for a contract migration', function () {
    pendingMigration($this->extra, '2099_01_01_000000_drop_locale', 'Contract', "Schema::table('users', fn (Blueprint \$t) => \$t->dropColumn('locale'));");
    freshBackup();

    expect(preflight()['maintenance']->result)->toBe('fail');

    app()->maintenanceMode()->activate([]);
    expect(preflight()['maintenance']->result)->toBe('warn')
        ->and(preflight()['migrations']->result)->toBe('pass');
});

it('lets an older release run on an expanded schema but not on a contracted one', function () {
    DB::table('migrations')->insert(['migration' => '2099_02_01_000000_newer_expand', 'batch' => 99]);
    DB::table('ops_migration_kinds')->insert(['migration' => '2099_02_01_000000_newer_expand', 'kind' => MigrationKind::Expand->value, 'recorded_at' => now()]);
    expect(preflight()['schema']->result)->toBe('warn');

    DB::table('migrations')->insert(['migration' => '2099_02_02_000000_newer_contract', 'batch' => 99]);
    DB::table('ops_migration_kinds')->insert(['migration' => '2099_02_02_000000_newer_contract', 'kind' => MigrationKind::Contract->value, 'recorded_at' => now()]);
    expect(preflight()['schema']->result)->toBe('fail');

    DB::table('ops_migration_kinds')->where('migration', '2099_02_02_000000_newer_contract')->delete();
    expect(preflight()['schema']->result)->toBe('fail');
});

it('refuses while jobs are still queued', function () {
    config(['queue.default' => 'database']);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

    expect(preflight()['queue']->result)->toBe('fail');

    DB::table('jobs')->delete();
    expect(preflight()['queue']->result)->toBe('pass');
});

it('requires a marker on every migration from ADR-0028 on', function () {
    $catalogue = new MigrationCatalogue(app('migrator'), [database_path('migrations')]);

    foreach ($catalogue->names() as $name) {
        if (strcmp($name, '2027_03_01') >= 0) {
            expect($catalogue->kind($name))->not->toBe(MigrationKind::Unmarked, "{$name} needs #[Expand] or #[Contract]");
            if ($catalogue->kind($name) === MigrationKind::Expand) {
                expect($catalogue->destructive($name))->toBeFalse("{$name} is marked expand but drops or renames");
            }
        }
    }
});
