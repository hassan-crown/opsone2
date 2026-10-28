<?php

namespace App\Actions\StateMachines\M16Appointment;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\StateMachine;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSettings;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use App\Workflow\Transition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-16-T00 (P-54 over the order): propose a measurement, installation, delivery or visit with address, time, duration
 * and actual members. A proposal reserves nothing; an overlap is saved with a warning (`conflicts` on the result).
 */
final class T00ProposeAppointment
{
    public function __construct(private readonly AppointmentBooking $booking, private readonly TransitionRecorder $transitions) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.implode(',', Appointment::KINDS)],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'member_ids' => ['required', 'array', 'min:1', 'max:10'],
            'member_ids.*' => ['integer'],
            'task_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  `ends_at` defaults to one slot (work settings) after `starts_at`
     * @return array{appointment: Appointment, conflicts: list<int>}
     */
    public function handle(User $actor, Order $order, array $data, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        $data = Validator::make($data, self::rules())->validate();
        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();
        $endsAt = isset($data['ends_at'])
            ? CarbonImmutable::parse($data['ends_at'])->utc()
            : $startsAt->addMinutes(WorkSettings::current()->appointment_slot_minutes);
        if ($endsAt->diffInHours($startsAt, true) > 72) {
            throw ValidationException::withMessages(['ends_at' => __('workflow.validation.duration_too_long')]);
        }

        return LockingTransaction::run(function () use ($actor, $order, $data, $startsAt, $endsAt, $source) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $appointment = new Appointment(['order_id' => $order->id, 'created_by' => $actor->id]);
            $appointment->setRelation('order', $order);
            Access::authorize($actor, Permission::P54, $appointment);
            if ($order->state()->isFinal()) {
                Transition::refuse(StateMachine::M16, 'T00', 'order_closed');
            }

            $members = $this->booking->validMembers($order, $data['member_ids']);
            if (isset($data['task_id']) && Task::query()->where('order_id', $order->id)->whereKey($data['task_id'])->doesntExist()) {
                throw ValidationException::withMessages(['task_id' => __('workflow.validation.task_invalid')]);
            }

            $appointment->fill([
                'task_id' => $data['task_id'] ?? null,
                'kind' => $data['kind'],
                'status' => AppointmentStatus::Proposed,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'address' => $data['address'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
            ])->save();
            $appointment->members()->sync($members);

            $conflicts = array_values($this->booking->conflicts($members, $startsAt, $endsAt)->pluck('id')->map(intval(...))->all());
            $this->transitions->record(StateMachine::M16, 'T00', $appointment, null, AppointmentStatus::Proposed, $actor, $source, context: [
                'order_id' => $order->id, 'conflicts' => $conflicts,
            ]);
            Audit::record(AuditAction::AppointmentProposed, $appointment, $actor, after: [
                'kind' => $appointment->kind, 'starts_at' => $startsAt->toIso8601String(), 'ends_at' => $endsAt->toIso8601String(), 'member_ids' => $members,
            ], source: $source, branchIds: [$order->branch_id], label: $order->number);

            return ['appointment' => $appointment, 'conflicts' => $conflicts];
        });
    }
}
