<?php

namespace App\Reports\Export;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Models\User;
use App\Notifications\Notifier;
use App\Reports\BranchComparison;
use App\Reports\Definitions\Support;
use App\Reports\ReportRegistry;
use App\Reports\ReportResult;
use App\Reports\ReportScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Report exports (SHR-12, NFR-16, ADR-0026): requested by a P-62 holder, generated on the queue from the SAME report
 * build the screen uses — evaluated against the user's grants at generation time — into a private file kept for a
 * short time. The file is reached only through a short-lived signed link bound to the user, which re-checks access.
 */
final class ReportExports
{
    public function __construct(private readonly Notifier $notifier) {}

    public static function canExport(User $user, string $key): bool
    {
        if (! Access::can($user, Permission::P62)) {
            return false;
        }
        if ($key === ReportRegistry::COMPARE) {
            return ReportRegistry::canCompare($user) && Access::can($user, Permission::P60);
        }
        $report = ReportRegistry::find($key);

        return $report !== null && ReportRegistry::canOpen($user, $report);
    }

    /**
     * @param  list<int>  $branchIds
     */
    public function request(User $user, string $key, string $from, string $to, array $branchIds, string $locale): ReportExport
    {
        if (! self::canExport($user, $key)) {
            abort(403);
        }
        if ($from > $to) {
            throw ValidationException::withMessages(['to' => __('reports.error.range')]);
        }

        $export = DB::transaction(function () use ($user, $key, $from, $to, $branchIds, $locale) {
            $export = ReportExport::query()->create([
                'uuid' => (string) Str::uuid(), 'user_id' => $user->id, 'report' => $key,
                'params' => ['from' => $from, 'to' => $to, 'branch_ids' => $branchIds, 'locale' => $locale],
                'status' => ReportExport::QUEUED,
            ]);
            Audit::record(AuditAction::ReportExportRequested, $export, $user, after: ['report' => $key, 'from' => $from, 'to' => $to, 'branch_ids' => $branchIds]);

            return $export;
        });
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    public function generate(ReportExport $export): void
    {
        if ($export->status !== ReportExport::QUEUED) {
            return;
        }
        $user = User::query()->find($export->user_id);
        $previousLocale = app()->getLocale();
        try {
            if ($user === null || ! self::canExport($user, $export->report)) {
                $this->fail($export, $user, 'access');

                return;
            }
            app()->setLocale((string) ($export->params['locale'] ?? 'ar'));
            Support::flush();
            $scope = new ReportScope($user, $export->params['from'], $export->params['to'], array_map('intval', $export->params['branch_ids']));
            $result = $export->report === ReportRegistry::COMPARE
                ? BranchComparison::build($scope)
                : (ReportRegistry::find($export->report) ?? throw new \LogicException('Unknown report.'))->build($scope);
            $csv = self::csv($result->toArray());

            $disk = (string) config('attachments.disk');
            $path = 'exports/'.now()->format('Y/m').'/'.$export->uuid.'.csv';
            Storage::disk($disk)->put($path, $csv['body']);

            DB::transaction(function () use ($export, $user, $disk, $path, $csv) {
                $export->forceFill([
                    'status' => ReportExport::READY, 'disk' => $disk, 'path' => $path,
                    'filename' => 'opsone-'.$export->report.'-'.$export->params['from'].'_'.$export->params['to'].'.csv',
                    'size_bytes' => strlen($csv['body']), 'sha256' => hash('sha256', $csv['body']), 'row_count' => $csv['rows'],
                    'truncated' => $csv['truncated'], 'ready_at' => now(),
                    'expires_at' => now()->addHours((int) config('reports.export_retention_hours', 24)),
                ])->save();
                Audit::record(AuditAction::ReportExportGenerated, $export, $user, after: ['report' => $export->report, 'rows' => $csv['rows'], 'size_bytes' => $export->size_bytes, 'sha256' => $export->sha256], source: 'system');
                $this->notifier->send($user, 'report.export_ready', ['report' => __('reports.title.'.$export->report)], null, 'report-export:'.$export->id);
            });
        } catch (Throwable $e) {
            report($e);
            $this->fail($export, $user, 'error');
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    private function fail(ReportExport $export, ?User $user, string $why): void
    {
        $export->forceFill(['status' => ReportExport::FAILED, 'error' => $why])->save();
        Audit::record(AuditAction::ReportExportFailed, $export, $user, after: ['report' => $export->report, 'why' => $why], source: 'system');
    }

    /**
     * The CSV of a report result: exactly its table rows, headers and enum values in the current locale.
     *
     * @param  array<string, mixed>  $payload  ReportResult::toArray()
     * @return array{body: string, rows: int, truncated: bool}
     */
    public static function csv(array $payload): array
    {
        $columns = $payload['table']['columns'];
        $header = array_values(array_map(fn (array $c) => (string) __('reports.col.'.$c['key']), $columns));
        $rows = [];
        foreach ($payload['table']['rows'] as $row) {
            $cells = [];
            foreach ($columns as $c) {
                $cells[] = self::cell($c['type'], $c['key'], $row[$c['key']] ?? null);
            }
            $rows[] = $cells;
        }

        return ['body' => CsvWriter::render($header, $rows), 'rows' => count($rows), 'truncated' => (bool) $payload['table']['truncated']];
    }

    /**
     * @return array{type: string, value: scalar|null}
     */
    private static function cell(string $type, string $key, mixed $value): array
    {
        if ($value === null) {
            return ['type' => 'text', 'value' => null];
        }

        return match ($type) {
            'money' => ['type' => 'number', 'value' => self::decimal((int) $value, 2, 100)],
            'qty' => ['type' => 'number', 'value' => self::decimal((int) $value, 3, 1000)],
            'int', 'hours', 'percent' => ['type' => 'number', 'value' => (string) $value],
            'enum' => ['type' => 'text', 'value' => __('reports.enum.'.$value) === 'reports.enum.'.$value ? (string) $value : __('reports.enum.'.$value)],
            default => ['type' => 'text', 'value' => (string) $value],
        };
    }

    private static function decimal(int $value, int $places, int $divisor): string
    {
        $abs = abs($value);

        return ($value < 0 ? '-' : '').intdiv($abs, $divisor).'.'.str_pad((string) ($abs % $divisor), $places, '0', STR_PAD_LEFT);
    }

    public function link(ReportExport $export, User $user): string
    {
        return URL::temporarySignedRoute(
            'reports.exports.download',
            now()->addMinutes((int) config('reports.export_link_ttl_minutes', 5)),
            ['export' => $export->uuid, 'u' => $user->id],
        );
    }

    public function purgeExpired(): int
    {
        $n = 0;
        foreach (ReportExport::query()->where('status', ReportExport::READY)->whereNull('purged_at')->where('expires_at', '<', now())->get() as $export) {
            if ($export->disk !== null && $export->path !== null) {
                Storage::disk($export->disk)->delete($export->path);
            }
            DB::transaction(function () use ($export) {
                $export->forceFill(['purged_at' => now()])->save();
                Audit::record(AuditAction::ReportExportPurged, $export, null, after: ['report' => $export->report], source: 'system');
            });
            $n++;
        }

        return $n;
    }
}
