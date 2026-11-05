<?php

namespace App\Http\Controllers\AppointmentRequests;

use App\Actions\AppointmentRequests\AssignAppointmentRequest;
use App\Actions\AppointmentRequests\CloseAppointmentRequest;
use App\AppointmentRequests\AppointmentRequestPresenter;
use App\AppointmentRequests\AppointmentRequests;
use App\Enums\AppointmentRequestCloseReason;
use App\Enums\AppointmentRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentRequests\AssignAppointmentRequestRequest;
use App\Http\Requests\AppointmentRequests\CloseAppointmentRequestRequest;
use App\Marketing\Attribution\Attribution;
use App\Models\AppointmentRequest;
use App\Models\Branch;
use App\Organization\OrgPresenter;
use App\Support\PropRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The appointment request queue (ADR-0039): new / assigned / scheduled / closed, assign, close with a reason, and
 * «schedule», which opens ApptSchedule prefilled from the request. Rows and opens are scoped by P-54.
 */
class AppointmentRequestController extends Controller
{
    public const PAGE = 50;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(AppointmentRequests::any($user), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'in:open,'.implode(',', AppointmentRequestStatus::values())],
            'mine' => ['nullable', 'boolean'],
            'branch' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'open';

        $base = AppointmentRequests::visible($user);
        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();
        $rows = $base->with(['branch', 'assignee'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', [AppointmentRequestStatus::New->value, AppointmentRequestStatus::Assigned->value]))
            ->when($status !== 'open', fn ($q) => $q->where('status', $status))
            ->when((bool) ($filters['mine'] ?? false), fn ($q) => $q->where('assignee_id', $user->id))
            ->when(isset($filters['branch']), fn ($q) => $q->where('branch_id', (int) $filters['branch']))
            // Open work: the oldest first (and the late ones on top); history: the latest first.
            ->when($status === 'open' || $status === 'new' || $status === 'assigned', fn ($q) => $q->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id'), fn ($q) => $q->orderByDesc('id'))
            ->limit(self::PAGE * 4)->get();

        return Inertia::render('appointment-requests/index', [
            'requests' => PropRows::of($rows->map(fn (AppointmentRequest $r) => AppointmentRequestPresenter::row($r))),
            'counts' => [
                'new' => $counts['new'] ?? 0, 'assigned' => $counts['assigned'] ?? 0,
                'scheduled' => $counts['scheduled'] ?? 0, 'closed' => $counts['closed'] ?? 0,
            ],
            'filters' => ['status' => $status, 'mine' => (bool) ($filters['mine'] ?? false), 'branch' => isset($filters['branch']) ? (string) $filters['branch'] : ''],
            'branches' => array_values(Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all()),
        ]);
    }

    public function show(Request $request, string $appointmentRequest): Response
    {
        $user = $request->user();
        $record = AppointmentRequests::find($user, $appointmentRequest);
        $record->load(['branch', 'assignee', 'customer', 'interest', 'order']);
        $open = $record->status->isOpen();

        return Inertia::render('appointment-requests/show', [
            'request' => AppointmentRequestPresenter::detail($record),
            'assignable' => $open ? AppointmentRequests::assignable($record) : [],
            'closeReasons' => AppointmentRequestCloseReason::values(),
            'can' => ['assign' => $open, 'close' => $open, 'schedule' => $open],
            'attribution' => Attribution::summaryOf($record),
        ]);
    }

    public function assign(AssignAppointmentRequestRequest $request, string $appointmentRequest, AssignAppointmentRequest $assign): RedirectResponse
    {
        $record = AppointmentRequests::find($request->user(), $appointmentRequest);
        $assign->handle($request->user(), $record, $request->integer('assignee_id'), $request->integer('lock_version') ?: null);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointment_requests.assigned')]);

        return to_route('appointment-requests.show', $record->id);
    }

    public function close(CloseAppointmentRequestRequest $request, string $appointmentRequest, CloseAppointmentRequest $close): RedirectResponse
    {
        $record = AppointmentRequests::find($request->user(), $appointmentRequest);
        $close->handle(
            $request->user(), $record, AppointmentRequestCloseReason::from($request->string('close_reason')->toString()),
            $request->input('close_note'), $request->integer('lock_version') ?: null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => __('appointment_requests.closed')]);

        return to_route('appointment-requests.show', $record->id);
    }
}
