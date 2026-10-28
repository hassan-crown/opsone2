<?php

namespace App\Workflow;

use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\AppointmentStatus;
use App\Enums\StateMachine;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\User;
use App\Support\StaleRecord;

/**
 * Loading and logging for M-16 Actions. Booking writes lock the members first (AppointmentBooking), then the order and
 * the appointment; other appointment writes lock the order and the appointment.
 */
final class AppointmentLog
{
    public function __construct(private readonly TransitionRecorder $transitions) {}

    public function lock(Appointment $appointment, ?int $expectedVersion): Appointment
    {
        $order = Order::query()->lockForUpdate()->findOrFail($appointment->order_id);
        $fresh = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);
        $fresh->setRelation('order', $order);

        if ($expectedVersion !== null && $fresh->lock_version !== $expectedVersion) {
            throw new StaleRecord($fresh->lock_version);
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(Appointment $appointment, ?string $code, AppointmentStatus $from, AppointmentStatus $to, User $actor, string $source, AuditAction $audit, ?string $reason = null, array $context = [], array $before = [], array $after = []): void
    {
        $appointment->lock_version++;
        $appointment->save();

        if ($code !== null) {
            $this->transitions->record(StateMachine::M16, $code, $appointment, $from, $to, $actor, $source, $reason, ['order_id' => $appointment->order_id, ...$context]);
        }
        Audit::record($audit, $appointment, $actor, before: $before, after: $after, reason: $reason, source: $source,
            branchIds: [$appointment->order->branch_id], label: $appointment->order->number);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Appointment $appointment): array
    {
        return [
            'status' => $appointment->status->value,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'conflicted' => $appointment->conflicted,
        ];
    }
}
