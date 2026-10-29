<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Reports\Export\ReportExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds one report export on the queue. One attempt: the export row carries the outcome (ready or failed).
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $exportId)
    {
        $this->afterCommit();
    }

    public function handle(ReportExports $exports): void
    {
        $export = ReportExport::query()->find($this->exportId);
        if ($export !== null) {
            $exports->generate($export);
        }
    }
}
