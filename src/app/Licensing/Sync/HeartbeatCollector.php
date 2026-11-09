<?php

namespace App\Licensing\Sync;

use App\Access\LicenseLimits;
use App\Ai\AiHeartbeat;
use App\Licensing\CurrentLicense;
use App\Operations\Backup\BackupStore;
use App\Operations\Health\HealthCheck;
use App\Operations\Health\Heartbeats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The sync request body (ADR-0047 §5, contract `sync/v1/heartbeat.schema.json`): closed fields only.
 *
 * Counts against the license limits and operational health; never names, contacts, addresses, user IPs, customer
 * or staff data, order/sales counts or amounts, raw logs, secrets or `.env` content. The central app rejects any
 * unknown field, and FIELDS below is the reviewed list a test pins: adding a field means changing both on purpose.
 *
 * Every probe is isolated: a failing database or disk still yields a well-formed heartbeat with safe fallbacks.
 */
final readonly class HeartbeatCollector
{
    public const int SCHEMA_VERSION = 1;

    public const int MAX_BYTES = 16384;

    /** @var array<string, list<string>|null> section → keys (null = scalar top-level field) */
    public const array FIELDS = [
        'schema_version' => null,
        'deployment_id' => null,
        'app' => ['code_version', 'last_migration'],
        'license' => ['license_id', 'status', 'problem'],
        'usage' => ['active_seats', 'active_branches', 'storage_mb', 'capabilities'],
        'health' => [
            'database_ok', 'redis_ok', 'failed_jobs', 'oldest_pending_job_seconds', 'scheduler_last_run_at',
            'backup_last_at', 'backup_verified', 'disk_free_percent', 'clock_skew_seconds',
        ],
    ];

    /** The optional `ai` block: monthly totals only (see App\Ai\AiHeartbeat). */
    public const array AI_FIELDS = ['period', 'credits_used', 'cost_usd_micro', 'suggestions_accepted', 'models'];

    public const array AI_MODEL_FIELDS = ['model', 'input_tokens', 'output_tokens', 'cached_tokens'];

    public function __construct(
        private CurrentLicense $license,
        private LicenseLimits $limits,
        private HealthCheck $health,
        private BackupStore $backups,
        private AiHeartbeat $ai,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(string $deploymentId, ?int $clockSkewSeconds): array
    {
        $snapshot = $this->license->snapshot();
        $payload = $snapshot->payload;
        $checks = self::attempt(fn () => $this->health->run(), []);

        $ai = self::attempt(fn () => $this->ai->block(), null);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'deployment_id' => $deploymentId,
            'app' => [
                'code_version' => self::token(config('ops.version'), '/^[A-Za-z0-9._+-]+$/', 50),
                'last_migration' => self::token(self::attempt(self::lastMigration(...), null), '/^[A-Za-z0-9._-]+$/', 191),
            ],
            'license' => [
                // The license in force: the installed one, or the last trusted one while it needs attention.
                'license_id' => self::token($payload?->licenseId, '/^[A-Za-z0-9._:-]+$/', 64),
                'status' => $snapshot->status->value,
                'problem' => $snapshot->problem?->value,
            ],
            'usage' => [
                'active_seats' => self::attempt(fn () => $this->limits->usedSeats(), 0),
                'active_branches' => self::attempt(fn () => $this->limits->activeBranches(), 0),
                'storage_mb' => (int) ceil(self::attempt(fn () => $this->limits->usedStorageBytes(), 0) / 1048576),
                'capabilities' => $payload === null ? [] : array_values(array_filter(
                    $payload->capabilities,
                    fn (string $capability) => preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $capability) === 1 && $snapshot->allows($capability),
                )),
            ],
            'health' => [
                'database_ok' => ($checks['database'] ?? 'fail') === 'ok',
                // Absent when this deployment does not use Redis: nothing to fail.
                'redis_ok' => ($checks['redis'] ?? 'ok') === 'ok',
                'failed_jobs' => self::attempt(self::failedJobs(...), 0),
                'oldest_pending_job_seconds' => self::attempt(self::oldestPendingJob(...), null),
                'scheduler_last_run_at' => self::attempt(fn () => Heartbeats::at(Heartbeats::SCHEDULER)?->toIso8601ZuluString(), null),
                'backup_last_at' => self::attempt(fn () => $this->backups->latest()?->createdAt->toIso8601ZuluString(), null),
                // No automatic restore test exists yet (ADR-0028): unknown, not "passed".
                'backup_verified' => null,
                'disk_free_percent' => self::diskFreePercent(),
                'clock_skew_seconds' => $clockSkewSeconds,
            ],
            // Optional (central C-AI): monthly AI totals, omitted when the facility used no AI this month.
            ...($ai === null ? [] : ['ai' => $ai]),
        ];
    }

    private static function lastMigration(): ?string
    {
        $table = (string) (config('database.migrations.table') ?? 'migrations');
        $name = DB::table($table)->orderByDesc('id')->value('migration');

        return is_string($name) ? $name : null;
    }

    private static function failedJobs(): int
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');

        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    /**
     * Only the database queue can be inspected without a driver-specific scan; other drivers report unknown.
     */
    private static function oldestPendingJob(): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');
        $oldest = Schema::hasTable($table) ? DB::table($table)->whereNull('reserved_at')->min('created_at') : null;

        return is_numeric($oldest) ? max(0, CarbonImmutable::now('UTC')->getTimestamp() - (int) $oldest) : null;
    }

    private static function diskFreePercent(): float
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || $total <= 0) {
            return 0.0;
        }

        return round(min(100, max(0, $free / $total * 100)), 1);
    }

    /**
     * A value matching the contract pattern, or null (never a free-form string).
     */
    private static function token(mixed $value, string $pattern, int $max): ?string
    {
        return is_string($value) && strlen($value) <= $max && preg_match($pattern, $value) === 1 ? $value : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $probe
     * @param  T  $fallback
     * @return T
     */
    private static function attempt(callable $probe, mixed $fallback): mixed
    {
        try {
            return $probe();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
