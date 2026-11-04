<?php

namespace App\Http\Controllers\FieldSales;

use App\Access\Permission;
use App\Access\ScopedQuery;
use App\Calendar\CalendarPresenter;
use App\Enums\AppointmentRequestStatus;
use App\Enums\InterestStatus;
use App\FieldSales\FieldDispatch;
use App\FieldSales\FieldReps;
use App\FieldSales\FieldSalesConfig;
use App\Http\Controllers\Controller;
use App\Http\Requests\FieldSales\DispatchRequest;
use App\Models\AppointmentRequest;
use App\Models\Branch;
use App\Models\FieldSales\FieldAssignment;
use App\Models\Interest;
use App\Models\User;
use App\Organization\OrgPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «توزيع البيع الميداني» (ADR-0041): the supervisor's board of open leads and appointment requests (of the kinds reps
 * handle) in their P-105 scope, who follows each and by when, the reps with their open work, and the dispatch action.
 */
class FieldDispatchController extends Controller
{
    public const LIMIT = 100;

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless(FieldDispatch::canDispatch($user), 403);
        $filter = in_array($request->query('show'), ['unassigned', 'assigned', 'all'], true) ? (string) $request->query('show') : 'unassigned';

        $leads = ScopedQuery::apply(Interest::query(), $user, [Permission::P105], 'branch_id', null, null)->with(['branch', 'assignee:id,name'])
            ->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])->orderBy('id')->limit(self::LIMIT)->get();
        $requests = ScopedQuery::apply(AppointmentRequest::query(), $user, [Permission::P105], 'branch_id', null, null)
            ->whereIn('status', [AppointmentRequestStatus::New->value, AppointmentRequestStatus::Assigned->value])
            ->whereIn('kind', FieldSalesConfig::kinds())->orderBy('id')->limit(self::LIMIT)->get();
        $open = FieldAssignment::query()->where('status', FieldAssignment::OPEN)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('subject_type', FieldAssignment::INTEREST)->whereIn('subject_id', $leads->pluck('id')))
                ->orWhere(fn ($q) => $q->where('subject_type', FieldAssignment::REQUEST)->whereIn('subject_id', $requests->pluck('id'))))
            ->with('rep:id,name')->get()->keyBy(fn (FieldAssignment $a) => $a->subject_type.':'.$a->subject_id);

        $keep = fn (?FieldAssignment $a) => $filter === 'all' || ($filter === 'assigned') === ($a !== null);
        $reps = FieldReps::all();
        $workload = FieldAssignment::query()->where('status', FieldAssignment::OPEN)->whereIn('rep_id', array_map(fn (User $u) => $u->id, $reps))
            ->groupBy('rep_id')->selectRaw('rep_id, COUNT(*) as c')->pluck('c', 'rep_id');
        $branchNames = $this->branchNames($leads, $requests);

        return Inertia::render('field-sales/index', [
            'filter' => $filter,
            'leads' => array_values($leads->filter(fn (Interest $i) => $keep($open->get(FieldAssignment::INTEREST.':'.$i->id)))->map(fn (Interest $i) => [
                'id' => $i->id, 'reference' => $i->reference, 'name' => $i->name, 'need' => $i->need, 'status' => $i->status->value,
                'branch_id' => $i->branch_id, 'branch' => OrgPresenter::name($i->branch), 'follower' => $i->assignee?->name,
            ] + $this->assignment($open->get(FieldAssignment::INTEREST.':'.$i->id)))->all()),
            'requests' => array_values($requests->filter(fn (AppointmentRequest $r) => $keep($open->get(FieldAssignment::REQUEST.':'.$r->id)))->map(fn (AppointmentRequest $r) => [
                'id' => $r->id, 'reference' => $r->reference, 'name' => $r->name, 'kind' => $r->kind, 'city' => $r->city,
                'status' => $r->status->value, 'lock_version' => $r->lock_version,
                'branch_id' => $r->branch_id, 'branch' => $branchNames[$r->branch_id] ?? '',
            ] + $this->assignment($open->get(FieldAssignment::REQUEST.':'.$r->id)))->all()),
            'reps' => array_map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'open' => (int) ($workload[$u->id] ?? 0),
                'branches' => array_values(array_filter(array_keys($branchNames), fn (int $b) => FieldReps::covers($u, $b))),
            ], $reps),
        ]);
    }

    public function assignLead(DispatchRequest $request, Interest $interest, FieldDispatch $dispatch): RedirectResponse
    {
        $dispatch->assignLead($this->user($request), $interest, $request->integer('rep_id'), $request->input('note'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.dispatched')]);

        return back();
    }

    public function assignRequest(DispatchRequest $request, AppointmentRequest $appointmentRequest, FieldDispatch $dispatch): RedirectResponse
    {
        $dispatch->assignRequest($this->user($request), $appointmentRequest, $request->integer('rep_id'),
            $request->filled('lock_version') ? $request->integer('lock_version') : null, $request->input('note'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('field_sales.flash.dispatched')]);

        return back();
    }

    /**
     * @return array{rep_id: int|null, rep: string|null, due_at: string|null, overdue: bool}
     */
    private function assignment(?FieldAssignment $a): array
    {
        return [
            'rep_id' => $a?->rep_id, 'rep' => $a?->rep->name,
            'due_at' => $a?->due_at === null ? null : CalendarPresenter::local($a->due_at)->format('Y-m-d H:i'),
            'overdue' => $a !== null && $a->isOverdue(),
        ];
    }

    /**
     * @param  Collection<int, Interest>  $leads
     * @param  Collection<int, AppointmentRequest>  $requests
     * @return array<int, string>
     */
    private function branchNames(Collection $leads, Collection $requests): array
    {
        $ids = $leads->pluck('branch_id')->merge($requests->pluck('branch_id'))->unique()->all();

        return Branch::query()->whereIn('id', $ids)->get()->mapWithKeys(fn ($b) => [(int) $b->id => OrgPresenter::name($b)])->all();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
