<?php

namespace App\Actions\Workflow;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\TaskStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\OrderMeasurement;
use App\Models\Task;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Workflow\AppointmentBooking;
use App\Workflow\Catalogue\CompletionMode;
use App\Workflow\WorkflowLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * COM-07 (P-14 over the order, or through an open measurement task assigned to the actor): record or correct an
 * estimated/final measurement. An ordinary correction is saved in place and audited, without a version. Changing
 * what an approved agreement relied on is routed to the change process in P6.
 */
final class RecordMeasurement
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'order_line_id' => ['nullable', 'integer'],
            'location' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'value' => ['required', 'numeric', 'min:0', 'max:999999999.999', 'decimal:0,3'],
            'unit' => ['required', 'in:'.implode(',', OrderMeasurement::UNITS)],
            'kind' => ['required', 'in:'.implode(',', OrderMeasurement::KINDS)],
            'measured_on' => ['required', 'date', 'before_or_equal:'.AppointmentBooking::today()],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, array $data, ?Task $task = null, ?OrderMeasurement $correcting = null, string $source = TransitionRecorder::SOURCE_WEB): OrderMeasurement
    {
        $data = Validator::make($data, self::rules())->validate();

        return LockingTransaction::run(function () use ($actor, $order, $data, $task, $correcting, $source) {
            $order = WorkflowLock::order($order->id);
            $viaTask = $this->authorize($actor, $order, $task);

            if (isset($data['order_line_id']) && OrderLine::query()->where('order_id', $order->id)->whereKey($data['order_line_id'])->doesntExist()) {
                throw ValidationException::withMessages(['order_line_id' => __('workflow.validation.line_invalid')]);
            }

            $values = [...$data, 'order_id' => $order->id, 'task_id' => $task?->id, 'recorded_by' => $actor->id];
            if ($correcting !== null) {
                $measurement = OrderMeasurement::query()->where('order_id', $order->id)->lockForUpdate()->findOrFail($correcting->id);
                // Through a task only the measurements of that task's action (its earlier or correction tasks).
                if ($viaTask && $task !== null && Task::query()->where('order_action_id', $task->order_action_id)->whereKey($measurement->task_id)->doesntExist()) {
                    throw new AuthorizationException(__('access.denied.no_grant'));
                }
                $before = $measurement->only(['location', 'name', 'value', 'unit', 'kind', 'measured_on']);
                $measurement->fill($values)->save();
                Audit::record(AuditAction::MeasurementCorrected, $order, $actor, before: $before,
                    after: $measurement->only(['location', 'name', 'value', 'unit', 'kind', 'measured_on']), source: $source, label: $order->number);
            } else {
                $measurement = OrderMeasurement::query()->create($values);
                Audit::record(AuditAction::MeasurementRecorded, $order, $actor,
                    after: $measurement->only(['location', 'name', 'value', 'unit', 'kind']), source: $source, label: $order->number);
            }

            return $measurement;
        });
    }

    /**
     * @return bool whether access comes only through the measurement task
     */
    private function authorize(User $actor, Order $order, ?Task $task): bool
    {
        if (Access::can($actor, Permission::P14, $order)) {
            return false;
        }

        // Through the measurement task assigned to them (matrix P-14: «الطلب أو المهمة المسندة»).
        if ($task !== null && $task->order_id === $order->id && $task->status->isOpen()
            && $task->action?->type->completion() === CompletionMode::Measurement) {
            $task->setRelation('order', $order);
            if (Access::can($actor, Permission::P14, $task) && in_array($task->status, [TaskStatus::Assigned, TaskStatus::InProgress, TaskStatus::Waiting], true)) {
                return true;
            }
        }

        throw new AuthorizationException(__('access.denied.no_grant'));
    }
}
