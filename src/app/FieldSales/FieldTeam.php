<?php

namespace App\FieldSales;

use App\Access\Access;
use App\Access\Permission;
use App\Calendar\CalendarPresenter;
use App\Enums\AppointmentStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InterestStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Installation\InstallationStatus;
use App\Models\Appointment;
use App\Models\Delivery\DeliveryNote;
use App\Models\FieldSales\FieldAssignment;
use App\Models\Finance\OrderDue;
use App\Models\Installation\InstallationRemark;
use App\Models\Installation\InstallationVisit;
use App\Models\Interest;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\Task;
use App\Models\User;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The supervisor's follow-up of their field team (ADR-0041): per rep, the leads that follow them, today's and upcoming
 * visits, what is late (dispatched leads past their response time, workflow tasks past their due date) and
 * conversions; then each rep's orders to the end — stage progress (factory and workshop stages are stages of the
 * flow), dues still owed, delivery, installation with its open remarks. Summaries only, each linking to the existing
 * screen; amounts only where the supervisor holds P-37 over the order. The team is `FieldRepAccess::team()`.
 */
final class FieldTeam
{
    public const UPCOMING_DAYS = 7;

    public const CONVERSION_DAYS = 30;

    public const ORDERS = 50;

    /**
     * @return list<array<string, mixed>>
     */
    public static function overview(User $supervisor): array
    {
        $out = [];
        foreach (FieldRepAccess::team($supervisor) as $rep) {
            [$today, $upcoming] = self::visitCounts($rep);
            $out[] = [
                'id' => $rep->id, 'name' => $rep->name,
                'leads' => self::leadCounts($rep),
                'visits_today' => $today, 'visits_upcoming' => $upcoming,
                'overdue' => self::overdueAssignments($rep)->count() + self::overdueTasks($rep)->count(),
                'conversions' => FieldAssignment::query()->where('rep_id', $rep->id)->where('outcome', 'converted')
                    ->where('ended_at', '>=', now()->subDays(self::CONVERSION_DAYS))->count(),
                'open_orders' => Order::query()->where('seller_id', $rep->id)->whereNotIn('status', ['closed', 'cancelled', 'delivered'])->count(),
            ];
        }

        return $out;
    }

    /**
     * One rep in detail.
     *
     * @return array<string, mixed>
     */
    public static function rep(User $supervisor, User $rep): array
    {
        $tz = AppointmentBooking::timezone();
        $leads = Interest::query()->where('assignee_id', $rep->id)
            ->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])->orderBy('id')->limit(100)->get();
        $due = FieldAssignment::query()->where('rep_id', $rep->id)->where('status', FieldAssignment::OPEN)
            ->where('subject_type', FieldAssignment::INTEREST)->pluck('due_at', 'subject_id');
        $visits = self::visits($rep)->where('starts_at', '>=', CarbonImmutable::now($tz)->startOfDay()->utc())
            ->where('starts_at', '<=', CarbonImmutable::now($tz)->addDays(self::UPCOMING_DAYS)->endOfDay()->utc())
            ->with('order.customer')->orderBy('starts_at')->limit(100)->get();
        $tasks = self::overdueTasks($rep)->with('order')->orderBy('due_at')->limit(100)->get();
        $orders = Order::query()->where('seller_id', $rep->id)->with('customer')->orderByDesc('id')->limit(self::ORDERS)->get();

        return [
            'rep' => ['id' => $rep->id, 'name' => $rep->name],
            'leads' => array_values($leads->map(function (Interest $i) use ($due) {
                $at = $due[$i->id] ?? null;
                $at = $at === null ? null : CarbonImmutable::parse($at);

                return [
                    'id' => $i->id, 'reference' => $i->reference, 'name' => $i->name, 'status' => $i->status->value,
                    'due_at' => $at === null ? null : CalendarPresenter::local($at)->format('Y-m-d H:i'),
                    'overdue' => $at !== null && $at->lte(now()),
                ];
            })->all()),
            'visits' => array_values($visits->map(fn (Appointment $a) => [
                'id' => $a->id, 'kind' => $a->kind, 'status' => $a->status->value,
                'at' => CalendarPresenter::local($a->starts_at)->format('Y-m-d H:i'),
                'today' => CalendarPresenter::local($a->starts_at)->isSameDay(CarbonImmutable::now($tz)),
                'customer' => $a->order->customer->name, 'order' => $a->order->number,
            ])->all()),
            'overdue_tasks' => array_values($tasks->map(fn (Task $t) => [
                'id' => $t->id, 'order' => $t->order->number, 'due_at' => $t->due_at === null ? null : CalendarPresenter::local($t->due_at)->format('Y-m-d H:i'),
            ])->all()),
            'orders' => self::orders($supervisor, array_values($orders->all())),
        ];
    }

    /**
     * Dispatched leads still open past their response time.
     *
     * @return Builder<FieldAssignment>
     */
    public static function overdueAssignments(User $rep): Builder
    {
        return FieldAssignment::query()->where('rep_id', $rep->id)->where('status', FieldAssignment::OPEN)
            ->whereNotNull('due_at')->where('due_at', '<=', now());
    }

    /**
     * Open workflow tasks of the rep past their due date (task SLA, ADR-0037).
     *
     * @return Builder<Task>
     */
    public static function overdueTasks(User $rep): Builder
    {
        return Task::query()->where('assignee_id', $rep->id)
            ->whereIn('status', array_map(fn (TaskStatus $s) => $s->value, TaskStatus::open()))
            ->whereNotNull('due_at')->where('due_at', '<=', now());
    }

    /**
     * @return array<string, int>
     */
    private static function leadCounts(User $rep): array
    {
        $counts = Interest::query()->where('assignee_id', $rep->id)
            ->whereIn('status', [InterestStatus::New->value, InterestStatus::Following->value])
            ->groupBy('status')->selectRaw('status, COUNT(*) as c')->pluck('c', 'status');

        return ['new' => (int) ($counts['new'] ?? 0), 'following' => (int) ($counts['following'] ?? 0)];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function visitCounts(User $rep): array
    {
        $tz = AppointmentBooking::timezone();
        $start = CarbonImmutable::now($tz)->startOfDay();
        $today = self::visits($rep)->whereBetween('starts_at', [$start->utc(), $start->endOfDay()->utc()])->count();
        $upcoming = self::visits($rep)->whereBetween('starts_at', [$start->addDay()->utc(), $start->addDays(self::UPCOMING_DAYS)->endOfDay()->utc()])->count();

        return [$today, $upcoming];
    }

    /**
     * @return Builder<Appointment>
     */
    private static function visits(User $rep): Builder
    {
        return Appointment::query()->whereIn('kind', FieldSalesConfig::kinds() === [] ? ['visit', 'measurement'] : FieldSalesConfig::kinds())
            ->whereIn('status', [AppointmentStatus::AwaitingConfirmation->value, AppointmentStatus::Confirmed->value, AppointmentStatus::Done->value])
            ->whereHas('members', fn ($q) => $q->where('users.id', $rep->id));
    }

    /**
     * @param  list<Order>  $orders
     * @return list<array<string, mixed>>
     */
    private static function orders(User $supervisor, array $orders): array
    {
        $ids = array_map(fn (Order $o) => $o->id, $orders);
        if ($ids === []) {
            return [];
        }
        $stages = OrderStage::query()->whereIn('order_id', $ids)->orderBy('position')->get()->groupBy('order_id');
        $active = DB::table('order_workflows')->whereIn('order_id', $ids)->pluck('active_stage_id', 'order_id');
        $dues = OrderDue::query()->whereIn('order_id', $ids)->groupBy('order_id')->selectRaw('order_id, SUM(amount_minor) as s')->pluck('s', 'order_id');
        $paid = DB::table('money_entries')->whereIn('order_id', $ids)->whereNotNull('order_due_id')
            ->groupBy('order_id')->selectRaw('order_id, SUM(amount_minor) as s')->pluck('s', 'order_id');
        $deliveries = DeliveryNote::query()->whereIn('order_id', $ids)->where('status', DeliveryStatus::Confirmed->value)
            ->groupBy('order_id')->selectRaw('order_id, COUNT(*) as c, MAX(CASE WHEN completes_order THEN 1 ELSE 0 END) as done')->get()->keyBy('order_id');
        $installs = InstallationVisit::query()->whereIn('order_id', $ids)->orderBy('id')->get()->groupBy('order_id');
        $remarks = InstallationRemark::query()->where('installation_remarks.status', InstallationRemark::OPEN)
            ->whereIn('installation_visits.order_id', $ids)->join('installation_visits', 'installation_visits.id', '=', 'installation_remarks.installation_visit_id')
            ->groupBy('installation_visits.order_id')->selectRaw('installation_visits.order_id as o, COUNT(*) as c')->pluck('c', 'o');

        return array_map(function (Order $o) use ($supervisor, $stages, $active, $dues, $paid, $deliveries, $installs, $remarks) {
            $list = $stages->get($o->id, collect());
            $current = $list->firstWhere('id', $active[$o->id] ?? null);
            $visit = $installs->get($o->id)?->last();
            $delivery = $deliveries->get($o->id);
            $money = Access::can($supervisor, Permission::P37, $o);

            return [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'customer' => $o->customer->name,
                'channel' => $o->sales_channel->value,
                'stage' => $current === null ? null : (string) $current->name->get(),
                'stages_done' => $list->filter(fn (OrderStage $s) => in_array($s->status, [StageStatus::Completed, StageStatus::CompletedNeedsCorrection], true))->count(),
                'stages_total' => $list->count(),
                'due_left_minor' => $money ? max(0, (int) ($dues[$o->id] ?? 0) - (int) ($paid[$o->id] ?? 0)) : null,
                'delivery' => $delivery === null ? 'none' : ((int) $delivery->getAttribute('done') === 1 ? 'complete' : 'partial'),
                'installation' => $visit === null ? null : InstallationStatus::of($visit)->value,
                'open_remarks' => (int) ($remarks[$o->id] ?? 0),
            ];
        }, $orders);
    }
}
