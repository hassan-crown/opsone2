<?php

namespace App\Http\Controllers\Calendar;

use App\Access\Access;
use App\Access\Permission;
use App\Calendar\CalendarPresenter;
use App\Customers\CustomerPresenter;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Order;
use App\Models\WorkSettings;
use App\Orders\OrderAccess;
use App\Organization\OrgPresenter;
use App\Workflow\AppointmentBooking;
use App\Workflow\AppointmentQueries;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CalWeek and the daily view (SHR-04, Q-32). Reads only what AppointmentQueries lets the user see; days are the
 * facility's local days, weeks start on Sunday.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P54) || Access::can($user, Permission::P24), 403);

        $filters = $request->validate([
            'view' => ['nullable', 'in:week,day'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'integer'],
            'member' => ['nullable', 'integer'],
        ]);
        $view = $filters['view'] ?? 'week';
        $tz = AppointmentBooking::timezone();
        $anchor = CarbonImmutable::parse($filters['date'] ?? 'now', $tz)->startOfDay();
        $from = $view === 'day' ? $anchor : $anchor->subDays($anchor->dayOfWeek);
        $to = $from->addDays($view === 'day' ? 1 : 7);

        $rows = AppointmentQueries::between($user, $from, $to, isset($filters['member']) ? [(int) $filters['member']] : null)
            ->when(isset($filters['branch']), fn ($c) => $c->filter(fn (Appointment $a) => $a->order->branch_id === (int) $filters['branch']));
        $rows->each(fn (Appointment $a) => $a->loadMissing(['order.branch', 'order.customer']));

        $settings = WorkSettings::current();
        $days = [];
        for ($d = $from; $d < $to; $d = $d->addDay()) {
            $days[] = ['date' => $d->format('Y-m-d'), 'working' => $settings->isWorkingDay($d), 'today' => $d->isSameDay(CarbonImmutable::now($tz))];
        }

        return Inertia::render('calendar/index', [
            'view' => $view,
            'date' => $anchor->format('Y-m-d'),
            'from' => $from->format('Y-m-d'),
            'days' => $days,
            'appointments' => array_values($rows->map(fn (Appointment $a) => CalendarPresenter::row($a, $user))->all()),
            'filters' => ['branch' => isset($filters['branch']) ? (string) $filters['branch'] : '', 'member' => isset($filters['member']) ? (string) $filters['member'] : ''],
            'branches' => array_values(Branch::query()->orderBy('id')->get()->map(fn (Branch $b) => ['id' => $b->id, 'name' => OrgPresenter::name($b)])->all()),
            'members' => CustomerPresenter::memberOptions(),
            'work' => ['starts_at' => $settings->work_starts_at, 'ends_at' => $settings->work_ends_at],
            'timezone' => $tz,
            'canSchedule' => Access::can($user, Permission::P54),
        ]);
    }

    /**
     * ApptSchedule.
     */
    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P54), 403);

        $orders = OrderAccess::visible($user)->with(['customer:id,name', 'branch'])->orderByDesc('orders.id')->limit(150)->get()
            ->filter(function (Order $o) use ($user) {
                $probe = new Appointment(['order_id' => $o->id, 'created_by' => $user->id]);
                $probe->setRelation('order', $o);

                return ! $o->state()->isFinal() && Access::can($user, Permission::P54, $probe);
            })->values();

        $settings = WorkSettings::current();

        return Inertia::render('calendar/schedule', [
            'orders' => array_values($orders->map(fn (Order $o) => [
                'id' => $o->id, 'number' => $o->number, 'customer' => $o->customer->name, 'branch' => OrgPresenter::name($o->branch),
            ])->all()),
            'orderId' => $request->integer('order') ?: null,
            'members' => CustomerPresenter::memberOptions(),
            'slotMinutes' => $settings->appointment_slot_minutes,
            'allowsConflicts' => $settings->allowsConflicts(),
            'timezone' => AppointmentBooking::timezone(),
            'date' => $request->string('date')->toString() ?: null,
        ]);
    }

    /**
     * Live clash preview for the schedule and change forms: the confirmed appointments overlapping the window, only
     * those the viewer may see, plus a count of the ones they may not.
     */
    public function conflicts(Request $request, AppointmentBooking $booking): JsonResponse
    {
        $user = $request->user();
        abort_unless(Access::can($user, Permission::P54), 403);
        $data = $request->validate([
            'member_ids' => ['required', 'array', 'min:1', 'max:10'],
            'member_ids.*' => ['integer'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['required', 'date_format:Y-m-d\TH:i', 'after:starts_at'],
            'exclude' => ['nullable', 'integer'],
        ]);

        $from = CalendarPresenter::parseLocal($data['starts_at']);
        $to = CalendarPresenter::parseLocal($data['ends_at']);
        // Same bound as a booking (T00/T03): a wider window is no preview and would load the whole schedule.
        if ($to->diffInHours($from, true) > 72) {
            throw ValidationException::withMessages(['ends_at' => __('workflow.validation.duration_too_long')]);
        }

        $clashes = $booking->conflicts(
            array_values(array_map(intval(...), $data['member_ids'])),
            $from,
            $to,
            isset($data['exclude']) ? (int) $data['exclude'] : null,
        );

        return response()->json(CalendarPresenter::clashes($user, $clashes) + ['allows_conflicts' => WorkSettings::current()->allowsConflicts()]);
    }
}
