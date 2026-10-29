<?php

namespace App\Console\Commands;

use App\Models\FacilityExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('ops:exports-prune')]
#[Description('Delete facility export files past their expiry (the request rows stay for the record)')]
class OpsExportsPruneCommand extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk((string) config('ops.export.disk', 'local'));
        $count = 0;

        FacilityExport::query()
            ->where(fn ($q) => $q->where('status', FacilityExport::READY)->where('expires_at', '<=', now())
                ->orWhere(fn ($s) => $s->whereIn('status', [FacilityExport::QUEUED, FacilityExport::RUNNING])->where('created_at', '<', now()->subDay())))
            ->each(function (FacilityExport $export) use ($disk, &$count): void {
                if ($export->path !== null) {
                    $disk->delete($export->path);
                }
                $export->forceFill(['status' => $export->status === FacilityExport::READY ? FacilityExport::EXPIRED : FacilityExport::FAILED, 'path' => null])->save();
                $count++;
            });

        $this->components->info("Expired {$count} export(s).");

        return self::SUCCESS;
    }
}
