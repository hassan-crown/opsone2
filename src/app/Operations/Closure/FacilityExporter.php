<?php

namespace App\Operations\Closure;

use App\Operations\Backup\DatabaseSnapshot;
use App\Operations\Backup\Workspace;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use ZipArchive;

/**
 * Full facility data export (Q-53 a, NFR-16): one CSV per table grouped by domain, the private files, and a README
 * with row counts and checksums, in a ZIP on the private disk. Credentials, sessions, one-time codes, encrypted
 * provider settings and queue internals are never exported.
 */
final class FacilityExporter
{
    /** Tables that are infrastructure or secrets, not facility data. */
    public const SKIP_TABLES = [
        'migrations', 'ops_migration_kinds', 'cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'passkeys', 'customer_otp_challenges', 'license_states', 'idempotency_keys',
        'facility_exports', 'outbox_messages', 'integration_settings', 'payment_gateway_sessions',
    ];

    /** Columns that hold secrets or their hashes. */
    public const SKIP_COLUMNS = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'token_hash',
        'code_hash', 'identifier_hash', 'verification_token', 'config', 'ip_hash',
    ];

    /** Domain folder by table prefix (first match wins). */
    private const DOMAINS = [
        'organization' => ['facility', 'branch', 'regions', 'departments', 'memberships', 'membership_', 'member_', 'role_', 'invitations', 'owner_invitations', 'ownership_', 'users'],
        'customers' => ['customer', 'interests'],
        'catalog' => ['catalog_'],
        'orders' => ['orders', 'order_lines', 'purchase_references', 'sequence_counters', 'state_transitions'],
        'workflow' => ['flow', 'order_workflows', 'order_stages', 'order_actions', 'tasks', 'order_measurements', 'appointment', 'work_settings'],
        'agreements' => ['agreement', 'customer_decisions', 'discount_'],
        'finance' => ['order_finances', 'order_dues', 'payment', 'receipts', 'money_', 'finance_settings', 'refunds', 'financial_disputes', 'external_documents'],
        'commissions' => ['commission_', 'order_sales', 'sale_corrections'],
        'inventory' => ['stock_', 'inventory_settings'],
        'procurement' => ['suppliers', 'purchase_'],
        'delivery' => ['delivery_'],
        'cancellation' => ['order_cancellation', 'settlement_'],
        'returns' => ['order_return', 'return_settings'],
        'store' => ['store_', 'coupon'],
        'site' => ['site_'],
        'support' => ['tickets', 'ticket_', 'support_', 'help_', 'whats_new_'],
        'shared' => ['audit_', 'attachments', 'user_notifications', 'notification_'],
    ];

    /**
     * @param  list<string>|null  $fileDisks
     * @return array{path: string, size: int, sha256: string, tables: int, rows: int, files: int}
     */
    public function build(string $relativePath, ?array $fileDisks = null): array
    {
        $fileDisks ??= array_values((array) config('ops.backup.file_disks', []));
        $snapshot = DatabaseSnapshot::on();
        $work = Workspace::create();

        try {
            $zipPath = $work->path('export.zip');
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot create the export archive.');
            }

            $readme = ['OpsOne facility export', 'Created (UTC): '.now('UTC')->toIso8601ZuluString(), '', 'Tables (rows, sha256):'];
            [$tables, $rows] = $snapshot->consistently(function () use ($snapshot, $work, $zip, &$readme): array {
                $tables = 0;
                $rows = 0;
                foreach ($snapshot->tables() as $table) {
                    if (in_array($table, self::SKIP_TABLES, true)) {
                        continue;
                    }
                    $entry = self::domain($table).'/'.$table.'.csv';
                    $path = $work->path($entry);
                    $count = $this->csv($snapshot, $table, $path);
                    $zip->addFile($path, $entry);
                    $readme[] = sprintf('  %s  %d  %s', $entry, $count, hash_file('sha256', $path));
                    $tables++;
                    $rows += $count;
                }

                return [$tables, $rows];
            });

            $files = 0;
            $readme[] = '';
            $readme[] = 'Private files (attachments.csv maps them to their records):';
            foreach ($fileDisks as $diskName) {
                $disk = Storage::disk($diskName);
                foreach ($disk->allFiles() as $file) {
                    if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
                        $zip->addFile($disk->path($file), "files/{$diskName}/{$file}");
                    } else {
                        $zip->addFromString("files/{$diskName}/{$file}", (string) $disk->get($file));
                    }
                    $files++;
                }
            }
            $readme[] = "  {$files} file(s) under files/";
            $zip->addFromString('README.txt', implode("\n", $readme)."\n");

            if ($zip->close() !== true) {
                throw new RuntimeException('Cannot write the export archive.');
            }

            $target = Storage::disk((string) config('ops.export.disk', 'local'));
            $stream = fopen($zipPath, 'rb') ?: throw new RuntimeException('Cannot read the export archive.');
            $target->writeStream($relativePath, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return [
                'path' => $relativePath,
                'size' => (int) filesize($zipPath),
                'sha256' => (string) hash_file('sha256', $zipPath),
                'tables' => $tables,
                'rows' => $rows,
                'files' => $files,
            ];
        } finally {
            $work->delete();
        }
    }

    public static function domain(string $table): string
    {
        foreach (self::DOMAINS as $domain => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($table, $prefix)) {
                    return $domain;
                }
            }
        }

        return 'other';
    }

    private function csv(DatabaseSnapshot $snapshot, string $table, string $path): int
    {
        $out = fopen($path, 'wb') ?: throw new RuntimeException("Cannot write {$path}.");
        fwrite($out, "\xEF\xBB\xBF");
        $keep = null;
        $count = -1;

        foreach ($snapshot->lines($table) as $line) {
            $values = (array) json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if ($keep === null) {
                $columns = array_values((array) $values['columns']);
                $keep = array_keys(array_filter($columns, fn ($c) => ! in_array($c, self::SKIP_COLUMNS, true)));
                fputcsv($out, array_map(fn (int $i) => self::cell($columns[$i]), $keep), escape: '');
                $count = 0;

                continue;
            }
            fputcsv($out, array_map(fn (int $i) => self::cell($values[$i] ?? null), $keep), escape: '');
            $count++;
        }
        fclose($out);

        return max(0, $count);
    }

    /**
     * Neutralizes spreadsheet formulas (CSV injection) and flattens binary values.
     */
    private static function cell(mixed $value): string
    {
        if (is_array($value)) {
            $value = isset($value['$b']) ? 'base64:'.$value['$b'] : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}
