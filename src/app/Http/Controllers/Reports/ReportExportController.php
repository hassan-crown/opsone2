<?php

namespace App\Http\Controllers\Reports;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportFilterRequest;
use App\Models\ReportExport;
use App\Models\User;
use App\Reports\Export\ReportExports;
use App\Reports\ReportRegistry;
use App\Reports\ReportScope;
use App\Workflow\AppointmentBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ReportExport (SHR-12, NFR-16, P-62): request an export, follow it, download it through a short-lived signed link.
 * A user sees and downloads only their own exports, and every step re-checks the live grants.
 */
class ReportExportController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P62), 403);

        $payload = [
            'exports' => ReportExport::query()->where('user_id', $user->id)->orderByDesc('id')->limit(30)->get()->map(fn (ReportExport $e) => $this->present($e))->all(),
            'reports' => array_values(array_filter([...array_keys(ReportRegistry::all()), ReportRegistry::COMPARE], fn (string $k) => ReportExports::canExport($user, $k))),
            'retention_hours' => (int) config('reports.export_retention_hours', 24),
            'defaults' => ['from' => ReportScope::defaultFrom(), 'to' => AppointmentBooking::today()],
        ];

        return $request->expectsJson() && ! $request->header('X-Inertia') ? response()->json($payload) : Inertia::render('reports/exports', $payload);
    }

    public function store(ReportFilterRequest $request, ReportExports $exports): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $key = (string) $request->validate(['report' => ['required', 'string', 'max:24']])['report'];
        abort_unless(ReportExports::canExport($user, $key), 403);

        $export = $exports->request($user, $key, $request->from(), $request->to(), $request->branchIds(), app()->getLocale());

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($this->present($export), 201)
            : redirect()->route('reports.exports.index')->with('status', 'report-export-queued');
    }

    public function link(Request $request, string $export, ReportExports $exports): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->own($user, $export);
        abort_unless(ReportExports::canExport($user, $model->report), 403);
        abort_unless($model->isDownloadable(), 410);

        return response()->json(['url' => $exports->link($model, $user), 'expires_in' => (int) config('reports.export_link_ttl_minutes', 5) * 60]);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($request->integer('u') === $user->id, 403);
        $model = $this->own($user, $export);
        abort_unless(ReportExports::canExport($user, $model->report), 403);
        abort_unless($model->isDownloadable() && $model->disk !== null && $model->path !== null, 410);

        DB::transaction(fn () => Audit::record(AuditAction::ReportExportDownloaded, $model, $user, after: ['report' => $model->report, 'sha256' => $model->sha256]));

        return Storage::disk($model->disk)->download((string) $model->path, (string) $model->filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function own(User $user, string $uuid): ReportExport
    {
        return ReportExport::query()->where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReportExport $e): array
    {
        return [
            'id' => $e->uuid, 'report' => $e->report, 'status' => $e->status, 'params' => $e->params,
            'row_count' => $e->row_count, 'truncated' => $e->truncated, 'size_bytes' => $e->size_bytes,
            'requested_at' => $e->created_at?->toIso8601String(), 'ready_at' => $e->ready_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(),
            'downloadable' => $e->isDownloadable(), 'expired' => $e->status === ReportExport::READY && ! $e->isDownloadable(),
            'error' => $e->error,
        ];
    }
}
