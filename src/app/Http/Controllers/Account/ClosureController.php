<?php

namespace App\Http\Controllers\Account;

use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Jobs\BuildFacilityExport;
use App\Licensing\CurrentLicense;
use App\Models\FacilityExport;
use App\Operations\Closure\ClosureAccess;
use App\Operations\Closure\ClosureReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facility closure (Q-53 a, FIN-12, NFR-16): readiness computed from the sources and a full data export built on the
 * queue, downloaded through a short signed link that re-checks the owner. Nothing here deletes data.
 */
class ClosureController extends Controller
{
    public function show(Request $request, ClosureReadiness $readiness, CurrentLicense $license): Response
    {
        abort_unless(ClosureAccess::allows($request->user()), 403);

        $items = $readiness->items();

        return Inertia::render('account/closure', [
            'items' => $items,
            'ready' => ClosureReadiness::ready($items),
            'subscription' => [
                'status' => $license->status()->value,
                'retention_days' => $license->snapshot()->payload?->retentionDays,
            ],
            'exports' => FacilityExport::query()->latest('id')->limit(5)->get()->map(fn (FacilityExport $e) => [
                'uuid' => $e->uuid,
                'status' => $e->status,
                'created_at' => $e->created_at->toIso8601ZuluString(),
                'expires_at' => $e->expires_at?->toIso8601ZuluString(),
                'size_bytes' => $e->size_bytes,
                'summary' => $e->summary,
                'download_url' => $e->downloadable()
                    ? URL::temporarySignedRoute('closure.exports.download', now()->addMinutes((int) config('ops.export.link_minutes', 30)), ['export' => $e->uuid])
                    : null,
            ])->values()->all(),
            'busy' => FacilityExport::query()->whereIn('status', [FacilityExport::QUEUED, FacilityExport::RUNNING])->exists(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(ClosureAccess::allows($user), 403);

        $created = DB::transaction(function () use ($user): bool {
            if (FacilityExport::query()->whereIn('status', [FacilityExport::QUEUED, FacilityExport::RUNNING])->lockForUpdate()->exists()) {
                return false;
            }
            $export = FacilityExport::query()->create([
                'uuid' => (string) Str::uuid(),
                'requested_by' => $user->id,
                'status' => FacilityExport::QUEUED,
            ]);
            Audit::record(AuditAction::FacilityExportRequested, $export, $user, after: ['export' => $export->uuid], label: $export->uuid);
            BuildFacilityExport::dispatch($export->id);

            return true;
        });

        Inertia::flash('toast', $created
            ? ['type' => 'success', 'message' => __('closure.export.queued')]
            : ['type' => 'info', 'message' => __('closure.export.busy')]);

        return back();
    }

    public function download(Request $request, FacilityExport $export): StreamedResponse
    {
        $user = $request->user();
        abort_unless(ClosureAccess::allows($user), 403);
        abort_unless($export->downloadable(), 410);

        DB::transaction(fn () => Audit::record(AuditAction::FacilityExportDownloaded, $export, $user, after: ['export' => $export->uuid, 'sha256' => $export->sha256], label: $export->uuid));

        return Storage::disk((string) config('ops.export.disk', 'local'))->download(
            (string) $export->path,
            'opsone-export-'.$export->created_at->format('Ymd-His').'.zip',
            ['Cache-Control' => 'no-store'],
        );
    }
}
