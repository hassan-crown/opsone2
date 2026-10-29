<?php

/**
 * «قبول النظام المتكامل» — row «تشغيل واستعادة»: فشل إشعار لا يلغي عملًا، واستعادة جهة مستقلة، وتحديث لا يقدم كودًا غير
 * متوافق.
 *
 * Requirements: SRS-SHR-05 (a failed channel is retried without voiding the original action), NFR-12 (encrypted
 * backup; a restore drill brings back one facility without other data), NFR-15 / SRS-SUB-13 (a failed or incompatible
 * migration never ships incompatible code), NFR-01 (isolation per deployment). ADR-0007 (outbox), ADR-0028
 * (operations), docs/runbook.md.
 *
 * The business action (inviting a staff member) goes through HTTP as the owner; operational steps run as the console
 * commands an operator uses (`outbox:process`, `ops:backup`, `ops:restore`, `ops:preflight`). Two deployments are
 * simulated, as in tests/Feature/Operations/BackupRestoreTest.php, by two SQLite files and two file disks.
 */

use App\Models\Invitation;
use App\Models\OutboxMessage;
use App\Notifications\OutboxStatus;
use App\Operations\Backup\BackupKey;
use App\Operations\Backup\BackupStore;
use App\Operations\Backup\DatabaseSnapshot;
use App\Operations\Upgrade\MigrationCatalogue;
use App\Operations\Upgrade\Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\Org;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->root = storage_path('framework/testing/acc10-'.getmypid().'-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->root);
});

afterEach(function () {
    foreach (['acc10_a', 'acc10_b'] as $name) {
        DB::purge($name);
    }
    app()->maintenanceMode()->deactivate();
    File::deleteDirectory($this->root);
});

it('keeps the invitation when its email fails, and the outbox retries it later without a second send', function () {
    $org = Org::make();
    accI10BreakMail();

    $this->actingAs($org->owner)->post(route('org.team.store'), [
        'name' => 'Sara', 'email' => 'sara@example.com', 'branch_ids' => [$org->a->id],
        'roles' => [['role_template_id' => Org::templateId('sales'), 'scope' => null]],
    ])->assertRedirect(route('org.team.invitations'));

    // The business action committed: the invitation and its audit entry exist although the mail server is down.
    $invitation = Invitation::query()->where('email', 'sara@example.com')->sole();
    $message = OutboxMessage::query()->sole();
    expect(DB::table('audit_events')->where('action', 'invitation.created')->where('subject_id', $invitation->id)->count())->toBe(1)
        ->and($message->status)->toBe(OutboxStatus::Failed)
        ->and($message->attempts)->toBe(1)
        ->and($message->next_attempt_at)->not->toBeNull()
        ->and((string) $message->last_error)->not->toContain('sara@example.com');
    $this->get(route('org.team.invitations'))->assertOk();

    // The server comes back; after the backoff the operator's queue sweep delivers it once.
    accI10RepairMail();
    $this->travel(5)->minutes();
    $this->artisan('outbox:process')->assertSuccessful();
    $this->artisan('outbox:process')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboxStatus::Sent)
        ->and($message->fresh()->attempts)->toBe(2)
        ->and(count(Mail::mailer('array')->getSymfonyTransport()->messages()))->toBe(1)
        ->and(OutboxMessage::query()->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(1);
});

it('restores one facility from its own encrypted backup without touching another deployment', function () {
    accI10TwoDeployments($this->root);
    config(['license.deployment_id' => 'deployment-a']);

    // The scheduled ops:backup of deployment A (its default connection and its private file disk).
    accI10OnDeployment('acc10_a', ['acc10_files_a'], fn () => $this->artisan('ops:backup')->expectsOutputToContain('written')->assertSuccessful()->run());
    $backups = app(BackupStore::class)->all();
    expect($backups)->toHaveCount(1);
    $name = $backups[0]->name;
    $atBackup = accI10Checksums('acc10_a');
    $tenantB = accI10Checksums('acc10_b');
    $tenantBFiles = accI10Files('acc10_files_b');
    $filesA = accI10Files('acc10_files_a');

    // Deployment A loses data after the backup.
    DB::connection('acc10_a')->table('users')->delete();
    DB::connection('acc10_a')->table('users')->insert(['name' => 'late', 'email' => 'late@a.test', 'password' => 'x']);
    Storage::disk('acc10_files_a')->delete('orders/1/proof.pdf');

    $database = DB::connection('acc10_a')->getDatabaseName();
    config(['ops.backup.file_disks' => ['acc10_files_a']]);
    $this->artisan('ops:restore', ['backup' => $name, '--connection' => 'acc10_a', '--force' => true, '--confirm' => $database])
        ->expectsOutputToContain('Restored and verified')->assertSuccessful();

    // A's rows and files are back as they were at the backup (the audit tables also hold the backup's own entry,
    // written after the snapshot); B's database and files are untouched.
    expect(accI10WithoutAudit(accI10Checksums('acc10_a')))->toBe(accI10WithoutAudit($atBackup))
        ->and(DB::connection('acc10_a')->table('users')->where('email', 'late@a.test')->exists())->toBeFalse()
        ->and(DB::connection('acc10_a')->table('users')->pluck('email')->sort()->values()->all())->toBe(['1@acc10_a.test', '2@acc10_a.test'])
        ->and(DB::connection('acc10_a')->table('facility')->value('name_en'))->toBe('Facility acc10_a')
        ->and(accI10Files('acc10_files_a'))->toBe($filesA)
        ->and(accI10Checksums('acc10_b'))->toBe($tenantB)
        ->and(accI10Files('acc10_files_b'))->toBe($tenantBFiles);
});

it('refuses to restore another deployment\'s backup or a backup from a newer schema, changing nothing', function () {
    accI10TwoDeployments($this->root);

    // Deployment A's backup restored on deployment B: refused before anything is replaced.
    config(['license.deployment_id' => 'deployment-a']);
    accI10OnDeployment('acc10_a', ['acc10_files_a'], fn () => $this->artisan('ops:backup')->assertSuccessful()->run());
    $foreign = app(BackupStore::class)->all()[0]->name;

    config(['license.deployment_id' => 'deployment-b']);
    $tenantB = accI10Checksums('acc10_b');
    $databaseB = DB::connection('acc10_b')->getDatabaseName();
    $this->artisan('ops:restore', ['backup' => $foreign, '--connection' => 'acc10_b', '--force' => true, '--confirm' => $databaseB])
        ->expectsOutputToContain('belongs to deployment deployment-a')->assertFailed();
    expect(accI10Checksums('acc10_b'))->toBe($tenantB);

    // A backup taken on a newer schema (a migration this code does not know) is refused too.
    DB::connection('acc10_b')->table('migrations')->insert(['migration' => '2099_01_01_000000_future_release', 'batch' => 99]);
    $this->travel(1)->minutes();
    accI10OnDeployment('acc10_b', ['acc10_files_b'], fn () => $this->artisan('ops:backup')->assertSuccessful()->run());
    $newer = collect(app(BackupStore::class)->all())->pluck('name')->reject(fn ($n) => $n === $foreign)->first();
    DB::connection('acc10_b')->table('migrations')->where('migration', '2099_01_01_000000_future_release')->delete();
    $tenantB = accI10Checksums('acc10_b');

    $this->artisan('ops:restore', ['backup' => $newer, '--connection' => 'acc10_b', '--force' => true, '--confirm' => $databaseB])
        ->expectsOutputToContain('newer schema')->assertFailed();
    expect(accI10Checksums('acc10_b'))->toBe($tenantB);
});

it('refuses an update whose migration is not marked #[Expand] or #[Contract], and passes once it is', function () {
    $extra = $this->root.'/migrations';
    File::ensureDirectoryExists($extra);
    config([
        'filesystems.disks.acc10_backups' => ['driver' => 'local', 'root' => $this->root.'/backups', 'throw' => true],
        'ops.backup.disk' => 'acc10_backups',
        'ops.backup.key' => BackupKey::generate(),
        'ops.backup.file_disks' => [],
    ]);
    accI10Catalogue($extra);

    // The current release with its own schema passes.
    $this->artisan('ops:preflight', ['--skip-backup-check' => true])->expectsOutputToContain('Preflight passed')->assertSuccessful();

    // The next release ships an unmarked migration: preflight refuses and nothing is migrated.
    accI10Migration($extra, '2099_03_01_000000_add_nickname', '');
    accI10Catalogue($extra);
    $this->artisan('ops:preflight', ['--skip-backup-check' => true])
        ->expectsOutputToContain('no #[Expand] or #[Contract] marker')->expectsOutputToContain('Preflight failed')->assertFailed();
    expect(DB::table('migrations')->where('migration', '2099_03_01_000000_add_nickname')->exists())->toBeFalse();

    // Marked as an additive change it passes, but only after a fresh backup exists.
    File::cleanDirectory($extra);
    accI10Migration($extra, '2099_03_01_000000_add_nickname', 'Expand');
    accI10Catalogue($extra);
    $this->artisan('ops:preflight')->assertFailed();
    $this->artisan('ops:backup')->assertSuccessful();
    $this->artisan('ops:preflight')->expectsOutputToContain('Preflight passed')->assertSuccessful();
});

/** A mailer whose server is down (its message names the server account, which must never be stored). */
function accI10BreakMail(): void
{
    Mail::extend('acc10boom', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('Connection could not be established with host smtp.example.com for sara@example.com');
        }

        public function __toString(): string
        {
            return 'acc10boom';
        }
    });
    config(['mail.default' => 'acc10boom', 'mail.mailers.acc10boom' => ['transport' => 'acc10boom']]);
    Mail::purge('acc10boom');
}

function accI10RepairMail(): void
{
    config(['mail.default' => 'array']);
    Mail::purge('array');
}

/**
 * Two deployments: SQLite files acc10_a / acc10_b (migrated, a facility and users each) and file disks with a proof
 * each, plus a private backup disk and a backup key (never the APP_KEY).
 */
function accI10TwoDeployments(string $root): void
{
    foreach (['acc10_a', 'acc10_b'] as $name) {
        touch("{$root}/{$name}.sqlite");
        config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => "{$root}/{$name}.sqlite", 'prefix' => '', 'foreign_key_constraints' => true]]);
    }
    foreach (['acc10_files_a', 'acc10_files_b', 'acc10_backups'] as $disk) {
        config(["filesystems.disks.{$disk}" => ['driver' => 'local', 'root' => "{$root}/{$disk}", 'throw' => true]]);
    }
    config(['ops.backup.disk' => 'acc10_backups', 'ops.backup.key' => BackupKey::generate(), 'ops.version' => '9.9.0-acc10']);

    foreach (['acc10_a', 'acc10_b'] as $tenant) {
        Artisan::call('migrate', ['--database' => $tenant, '--force' => true]);
        $db = DB::connection($tenant);
        $db->table('facility')->insert(['name_ar' => 'منشأة '.$tenant, 'name_en' => 'Facility '.$tenant, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1, 2] as $i) {
            $db->table('users')->insert(['name' => "{$tenant} user {$i}", 'email' => "{$i}@{$tenant}.test", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    Storage::disk('acc10_files_a')->put('orders/1/proof.pdf', '%PDF facility A');
    Storage::disk('acc10_files_b')->put('orders/1/proof.pdf', '%PDF facility B');
}

/**
 * Runs an operator command as deployment `$connection` would: that connection is the default and those disks are its
 * private files. The test's own connection is restored afterwards (RefreshDatabase rolls it back).
 *
 * @param  list<string>  $disks
 */
function accI10OnDeployment(string $connection, array $disks, Closure $run): mixed
{
    $default = config('database.default');
    $fileDisks = config('ops.backup.file_disks');
    config(['database.default' => $connection, 'ops.backup.file_disks' => $disks]);
    DB::setDefaultConnection($connection);
    try {
        return $run();
    } finally {
        config(['database.default' => $default, 'ops.backup.file_disks' => $fileDisks]);
        DB::setDefaultConnection($default);
    }
}

/**
 * @return array<string, array{rows: int, sha256: string}>
 */
function accI10Checksums(string $connection): array
{
    $snapshot = DatabaseSnapshot::on($connection);
    $result = [];
    foreach ($snapshot->tables() as $table) {
        $result[$table] = $snapshot->checksum($table);
    }

    return $result;
}

/**
 * @param  array<string, mixed>  $checksums
 * @return array<string, mixed>
 */
function accI10WithoutAudit(array $checksums): array
{
    unset($checksums['audit_events'], $checksums['audit_event_branches']);

    return $checksums;
}

/**
 * @return array<string, string>
 */
function accI10Files(string $disk): array
{
    $result = [];
    foreach (Storage::disk($disk)->allFiles() as $path) {
        $result[$path] = hash('sha256', (string) Storage::disk($disk)->get($path));
    }
    ksort($result);

    return $result;
}

/** The release's migrations = the app's plus the pending ones of the next release in `$extra`. */
function accI10Catalogue(string $extra): void
{
    app()->instance(MigrationCatalogue::class, new MigrationCatalogue(app('migrator'), [database_path('migrations'), $extra]));
    app()->forgetInstance(Preflight::class);
}

function accI10Migration(string $dir, string $name, string $marker): void
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
                Schema::table('users', fn (Blueprint \$t) => \$t->string('nickname')->nullable());
            }

            public function down(): void
            {
                Schema::table('users', fn (Blueprint \$t) => \$t->dropColumn('nickname'));
            }
        };
        PHP);
}
