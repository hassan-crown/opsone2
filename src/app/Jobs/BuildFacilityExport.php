<?php

namespace App\Jobs;

use App\Models\FacilityExport;
use App\Operations\Closure\FacilityExporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds a requested facility export on the queue (Q-53 a). One attempt: a failure is shown on the closure screen and
 * the owner may request again.
 */
class BuildFacilityExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $exportId)
    {
        $this->afterCommit();
    }

    public function handle(FacilityExporter $exporter): void
    {
        $export = FacilityExport::query()->find($this->exportId);
        if ($export === null || $export->status !== FacilityExport::QUEUED) {
            return;
        }
        $export->forceFill(['status' => FacilityExport::RUNNING])->save();

        try {
            $result = $exporter->build(trim((string) config('ops.export.directory', 'exports'), '/').'/'.$export->uuid.'.zip');
        } catch (Throwable $e) {
            report($e);
            $export->forceFill(['status' => FacilityExport::FAILED, 'failure' => 'build_failed'])->save();

            return;
        }

        $export->forceFill([
            'status' => FacilityExport::READY,
            'path' => $result['path'],
            'size_bytes' => $result['size'],
            'sha256' => $result['sha256'],
            'summary' => ['tables' => $result['tables'], 'rows' => $result['rows'], 'files' => $result['files']],
            'ready_at' => now(),
            'expires_at' => now()->addHours((int) config('ops.export.ttl_hours', 24)),
        ])->save();
    }
}
