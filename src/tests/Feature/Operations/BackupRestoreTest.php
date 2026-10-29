<?php

use App\Operations\Backup\BackupCipher;
use App\Operations\Backup\BackupKey;
use App\Operations\Backup\BackupManifest;
use App\Operations\Backup\BackupRestorer;
use App\Operations\Backup\BackupStore;
use App\Operations\Backup\BackupWriter;
use App\Operations\Backup\DatabaseSnapshot;
use App\Operations\Backup\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/*
| Restore drill (NFR-12, Q-54, ADR-0028). Two deployments are simulated with two SQLite connections and two file
| disks — separate configs, as in production (ADR-0002). A backup of tenant A restores into a clean database with
| identical row counts and checksums, and nothing of tenant B changes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/ops-'.getmypid().'-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->root);

    foreach (['tenant_a', 'tenant_b', 'tenant_a_restored'] as $name) {
        touch("{$this->root}/{$name}.sqlite");
        config(["database.connections.{$name}" => [
            'driver' => 'sqlite', 'database' => "{$this->root}/{$name}.sqlite", 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
    }
    foreach (['files_a', 'files_b', 'files_a_restored', 'backups_test'] as $disk) {
        config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => "{$this->root}/{$disk}", 'throw' => true]]);
    }
    config([
        'ops.backup.disk' => 'backups_test',
        'ops.backup.key' => $this->key = BackupKey::generate(),
        'ops.version' => '9.9.0-test',
    ]);

    foreach (['tenant_a', 'tenant_b'] as $tenant) {
        Artisan::call('migrate', ['--database' => $tenant, '--force' => true]);
        $db = DB::connection($tenant);
        $db->table('facility')->insert(['name_ar' => 'منشأة '.$tenant, 'name_en' => 'Facility '.$tenant, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 3) as $i) {
            $db->table('users')->insert([
                'name' => "{$tenant} user {$i}", 'email' => "{$i}@{$tenant}.test", 'password' => 'x',
                // A non UTF-8 value survives the round trip.
                'two_factor_secret' => $i === 1 ? "\xff\xfe\x00raw" : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
    Storage::disk('files_a')->put('orders/1/proof.pdf', '%PDF tenant A');
    Storage::disk('files_a')->put('tickets/7/photo.jpg', random_bytes(2048));
    Storage::disk('files_b')->put('orders/1/proof.pdf', '%PDF tenant B');
});

afterEach(function () {
    foreach (['tenant_a', 'tenant_b', 'tenant_a_restored'] as $name) {
        DB::purge($name);
    }
    File::deleteDirectory($this->root);
});

/**
 * @return array<string, array{rows: int, sha256: string}>
 */
function checksumsOf(string $connection): array
{
    $snapshot = DatabaseSnapshot::on($connection);
    $result = [];
    foreach ($snapshot->tables() as $table) {
        $result[$table] = $snapshot->checksum($table);
    }

    return $result;
}

/**
 * @return array<string, string>
 */
function filesOf(string $disk): array
{
    $result = [];
    foreach (Storage::disk($disk)->allFiles() as $path) {
        $result[$path] = hash('sha256', (string) Storage::disk($disk)->get($path));
    }
    ksort($result);

    return $result;
}

function backupTenantA(): string
{
    $file = app(BackupWriter::class)->run('tenant_a', ['files_a']);

    return $file->name;
}

it('restores a backup into a clean database with identical rows, checksums and files, never touching tenant B', function () {
    $atBackup = checksumsOf('tenant_a');
    $tenantB = checksumsOf('tenant_b');
    $tenantBFiles = filesOf('files_b');

    $name = backupTenantA();

    // Work continues after the backup; the restore returns the snapshot, not this.
    DB::connection('tenant_a')->table('users')->insert(['name' => 'late', 'email' => 'late@a.test', 'password' => 'x']);

    $work = Workspace::create();
    $store = app(BackupStore::class);
    $store->download($store->find($name), $local = $work->path('a.opsbak'));
    $restorer = app(BackupRestorer::class);
    $backup = $restorer->inspect($local, BackupKey::fromConfig(), $work);

    expect($restorer->assertCompatible($backup->manifest, 'sqlite', null))->toBe([])
        ->and($backup->manifest->appVersion)->toBe('9.9.0-test')
        ->and($backup->manifest->tables['users']['rows'])->toBe(3)
        ->and($backup->manifest->tables['sessions']['data'])->toBeFalse();

    $result = $restorer->restore($backup, 'tenant_a_restored', ['files_a' => 'files_a_restored']);
    $backup->close();
    $work->delete();

    expect($result->verified())->toBeTrue()
        ->and(checksumsOf('tenant_a_restored'))->toBe($atBackup)
        ->and(DB::connection('tenant_a_restored')->table('users')->where('email', 'late@a.test')->exists())->toBeFalse()
        ->and(DB::connection('tenant_a_restored')->table('users')->where('email', '1@tenant_a.test')->value('two_factor_secret'))->toBe("\xff\xfe\x00raw")
        ->and(filesOf('files_a_restored'))->toBe(filesOf('files_a'))
        ->and(checksumsOf('tenant_b'))->toBe($tenantB)
        ->and(filesOf('files_b'))->toBe($tenantBFiles);

    // The restored schema accepts new rows (auto-increment and constraints came back).
    DB::connection('tenant_a_restored')->table('users')->insert(['name' => 'next', 'email' => 'next@a.test', 'password' => 'x']);
    expect(DB::connection('tenant_a_restored')->table('users')->max('id'))->toBe(4);
});

it('restores in place over a changed database through the command, with --force and the typed name', function () {
    $atBackup = checksumsOf('tenant_a');
    $tenantB = checksumsOf('tenant_b');
    $name = backupTenantA();
    DB::connection('tenant_a')->table('users')->delete();
    $database = DB::connection('tenant_a')->getDatabaseName();

    $this->artisan('ops:restore', ['backup' => $name, '--connection' => 'tenant_a'])
        ->expectsOutputToContain('Re-run with --force')->assertFailed();
    $this->artisan('ops:restore', ['backup' => $name, '--connection' => 'tenant_a', '--force' => true, '--confirm' => 'tenant_b'])
        ->expectsOutputToContain('Nothing was changed')->assertFailed();
    expect(DB::connection('tenant_a')->table('users')->count())->toBe(0);

    $this->artisan('ops:restore', ['backup' => $name, '--connection' => 'tenant_a', '--verify-only' => true])->assertSuccessful();
    expect(DB::connection('tenant_a')->table('users')->count())->toBe(0);

    config(['ops.backup.file_disks' => []]);
    $this->artisan('ops:restore', ['backup' => $name, '--connection' => 'tenant_a', '--force' => true, '--confirm' => $database])
        ->expectsOutputToContain('Restored and verified')->assertSuccessful();

    expect(checksumsOf('tenant_a'))->toBe($atBackup)
        ->and(checksumsOf('tenant_b'))->toBe($tenantB);
});

it('refuses a damaged backup, the wrong key and an APP_KEY used as backup key', function () {
    $name = backupTenantA();
    $store = app(BackupStore::class);
    $work = Workspace::create();
    $store->download($store->find($name), $local = $work->path('a.opsbak'));

    $other = BackupKey::fromString(BackupKey::generate());
    expect(fn () => app(BackupRestorer::class)->inspect($local, $other, $work))->toThrow(RuntimeException::class, 'different OPS_BACKUP_KEY');

    $bytes = (string) file_get_contents($local);
    $bytes[strlen($bytes) - 10] = chr(ord($bytes[strlen($bytes) - 10]) ^ 1);
    file_put_contents($tampered = $work->path('tampered.opsbak'), $bytes);
    expect(fn () => app(BackupRestorer::class)->inspect($tampered, BackupKey::fromConfig(), $work))->toThrow(RuntimeException::class, 'damaged');

    file_put_contents($truncated = $work->path('truncated.opsbak'), substr((string) file_get_contents($local), 0, -40));
    expect(fn () => app(BackupRestorer::class)->inspect($truncated, BackupKey::fromConfig(), $work))->toThrow(RuntimeException::class);

    // The sidecar checksum catches a replaced file before any decryption.
    Storage::disk('backups_test')->put('opsone/'.$name, $bytes);
    expect(fn () => $store->download($store->find($name), $work->path('again.opsbak')))->toThrow(RuntimeException::class, 'checksum');
    $work->delete();

    config(['app.key' => 'base64:'.$this->key]);
    expect(fn () => BackupKey::fromConfig())->toThrow(RuntimeException::class, 'differ from APP_KEY');
    config(['ops.backup.key' => null]);
    expect(fn () => BackupKey::fromConfig())->toThrow(RuntimeException::class, 'not set');
});

it('refuses a backup from a newer schema, another engine or another deployment', function () {
    $restorer = app(BackupRestorer::class);
    $manifest = fn (array $overrides) => BackupManifest::fromJson((string) json_encode([
        'format' => 1, 'driver' => 'sqlite', 'deployment_id' => 'deployment-a',
        'migrations' => ['names' => ['0001_01_01_000000_create_users_table'], 'batch' => 1],
        'tables' => [], 'files' => [], 'entries' => [],
        ...$overrides,
    ]));

    expect(fn () => $restorer->assertCompatible($manifest(['migrations' => ['names' => ['2099_01_01_000000_future'], 'batch' => 9]]), 'sqlite', null))
        ->toThrow(RuntimeException::class, 'newer schema')
        ->and(fn () => $restorer->assertCompatible($manifest([]), 'mysql', null))->toThrow(RuntimeException::class, 'taken on sqlite')
        ->and(fn () => $restorer->assertCompatible($manifest([]), 'sqlite', 'deployment-b'))->toThrow(RuntimeException::class, 'belongs to deployment')
        ->and($restorer->assertCompatible($manifest([]), 'sqlite', 'deployment-b', allowOtherDeployment: true))->not->toBeEmpty();
});

it('keeps backups private and prunes past the retention while keeping the newest', function () {
    config(['ops.backup.disk' => 'public']);
    expect(fn () => app(BackupStore::class)->disk())->toThrow(RuntimeException::class, 'private disk');
    config(['ops.backup.disk' => 'backups_test']);

    $writer = app(BackupWriter::class);
    $now = CarbonImmutable::now('UTC');
    foreach ([45, 31, 2] as $days) {
        $writer->run('tenant_b', [], at: $now->subDays($days));
    }

    $store = app(BackupStore::class);
    expect($store->prune(30, $now))->toHaveCount(2)
        ->and(array_map(fn ($f) => $f->createdAt->toDateString(), $store->all()))->toBe([$now->subDays(2)->toDateString()]);

    // Even far past the retention, the newest backup stays.
    expect($store->prune(30, $now->addDays(400)))->toBe([])->and($store->all())->toHaveCount(1);

    // The encrypted file never contains plaintext rows.
    $raw = (string) Storage::disk('backups_test')->get($store->all()[0]->path);
    expect(str_starts_with($raw, BackupCipher::MAGIC))->toBeTrue()->and($raw)->not->toContain('tenant_b user');
});

it('schedules the daily backup with pruning and the heartbeat', function () {
    $commands = array_map(fn (Event $e) => (string) $e->command, app(Schedule::class)->events());

    expect(collect($commands)->contains(fn (string $c) => str_contains($c, 'ops:backup --prune')))->toBeTrue()
        ->and(collect($commands)->contains(fn (string $c) => str_contains($c, 'ops:heartbeat')))->toBeTrue()
        ->and(collect($commands)->contains(fn (string $c) => str_contains($c, 'ops:exports-prune')))->toBeTrue();
});
