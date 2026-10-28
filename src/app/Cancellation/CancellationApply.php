<?php

namespace App\Cancellation;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T02ReleaseReservation;
use App\Agreements\AgreementActivation;
use App\Agreements\ContentHash;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\CommissionAccrual;
use App\Enums\AppointmentStatus;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\StateMachine;
use App\Enums\TaskStatus;
use App\Finance\FinanceLock;
use App\Finance\OrderSchedule;
use App\Inventory\Quantity;
use App\Inventory\StockLedger;
use App\Models\Agreements\CustomerDecision;
use App\Models\Appointment;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Cancellation\OrderCancellationLine;
use App\Models\Finance\Refund;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Settlement\SettlementItem;
use App\Models\Task;
use App\Models\User;
use App\Settlement\SettlementFollowUps;
use App\Workflow\AppointmentBooking;
use App\Workflow\AppointmentLog;
use App\Workflow\TaskLog;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Applies an accepted cancellation (M-34-T02/T03 with M-04-T05 / M-05-T09), all or nothing, inside the caller's
 * transaction with the order row locked first. The impact is computed again under the locks and must equal the one
 * the reviewer confirmed (`preview_stale` otherwise): a delivery, a payment or a task change in between makes the
 * reviewer look again instead of applying something they did not see.
 *
 * Effects, each through its own domain: open tasks cancelled and upcoming appointments cancelled (full, custom); open
 * reservations released (M-24, never what was issued); cancelled quantities recorded; the agreement re-priced as a new
 * version (P6 AgreementActivation) with the schedule following it and the commission re-derived; the order state; the
 * settlement follow-up with what remains (refund through M-12, supplier orders, …); the decision's messages.
 */
final class CancellationApply
{
    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly StockLedger $ledger,
        private readonly T02ReleaseReservation $release,
        private readonly AgreementActivation $activation,
        private readonly OrderSchedule $schedule,
        private readonly CommissionAccrual $commission,
        private readonly SettlementFollowUps $settlement,
        private readonly TaskLog $tasks,
        private readonly AppointmentLog $appointments,
        private readonly AppointmentBooking $booking,
        private readonly CancellationNotices $notices,
    ) {}

    /**
     * @param  string  $code  M-34 transition: T02 (accept a pending request) or T03 (record and accept at once)
     */
    public function apply(User $actor, Order $order, OrderCancellation $cancellation, string $hash, string $code, string $source): OrderCancellation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A cancellation is applied inside its decision\'s transaction.');
        }
        $impact = CancellationImpact::compute($order, CancellationInput::of($cancellation), $cancellation);
        if (! hash_equals($impact['hash'], $hash)) {
            throw new InvalidTransition(StateMachine::M34, $code, 'preview_stale');
        }
        $reason = $cancellation->reason;
        $label = $cancellation->number;

        /** @var array{tasks: list<array{id: int}>, appointments: list<array{id: int}>} $stops */
        $stops = $impact['stops'];
        $this->stopTasks($actor, $stops['tasks'], $reason, $source);
        $this->stopAppointments($actor, $stops['appointments'], $reason, $source);
        $released = $this->releaseStock($actor, $impact['settlement']['stock'], $reason, $source);

        foreach ($impact['lines'] as $line) {
            OrderCancellationLine::query()->create([
                'order_cancellation_id' => $cancellation->id, 'order_id' => $order->id, 'order_line_id' => $line['order_line_id'],
                'agreement_line' => $line['agreement_line'], 'description' => mb_substr($line['description'], 0, 500),
                'quantity_milli' => $line['quantity_milli'], 'value_minor' => $line['value_minor'],
            ]);
            // Before an agreement a custom line has no price: cancelling it is removing it, kept in the history (COM-11).
            if (! $order->isReady() && $line['agreement_line'] === null && $line['order_line_id'] !== null && ! $cancellation->isFull()) {
                OrderLine::query()->whereKey($line['order_line_id'])->whereNull('removed_at')->update(['removed_at' => now(), 'removed_by' => $actor->id]);
            }
        }

        $commission = null;
        if ($impact['content'] !== null) {
            $commission = $this->reprice($actor, $order, $cancellation, $impact['content'], $source);
        } elseif ($order->isReady() && $impact['settlement']['money']['value_after_minor'] !== null) {
            $order->total_minor = (int) $impact['settlement']['money']['value_after_minor'];
        }

        $this->moveOrder($actor, $order, $cancellation, $impact['order']['status_after'], $released, $source);
        $this->openSettlement($order, $cancellation, $impact, $released, $commission);

        $from = $code === 'T03' ? null : $cancellation->status;
        $cancellation->forceFill([
            'status' => CancellationStatus::Accepted, 'impact' => $impact, 'impact_hash' => $impact['hash'],
            'decided_by' => $actor->id, 'decided_at' => now(), 'active_key' => null, 'lock_version' => $cancellation->lock_version + 1,
        ])->save();
        $this->transitions->record(StateMachine::M34, $code, $cancellation, $from, CancellationStatus::Accepted, $actor, $source, $reason, [
            'order_id' => $order->id, 'scope' => $cancellation->scope, 'hash' => $impact['hash'],
        ]);
        Audit::record(AuditAction::CancellationAccepted, $order, $actor, before: ['status' => $impact['order']['status']], after: [
            'cancellation' => $label, 'scope' => $cancellation->scope, 'status' => $impact['order']['status_after'],
            'refund_minor' => $impact['settlement']['money']['refund_minor'], 'released' => implode(', ', $released),
        ], reason: $reason, source: $source, label: $order->number);
        $this->notices->accepted($cancellation, $order, $actor);

        return $cancellation;
    }

    /**
     * @param  list<array{id: int}>  $tasks
     */
    private function stopTasks(User $actor, array $tasks, string $reason, string $source): void
    {
        foreach ($tasks as $row) {
            $task = Task::query()->lockForUpdate()->findOrFail($row['id']);
            $from = $task->status;
            if (! in_array($from, TaskStatus::open(), true)) {
                continue;
            }
            $task->forceFill(['status' => TaskStatus::Cancelled, 'cancel_reason' => $reason]);
            $this->tasks->record($task, 'T07', $from, TaskStatus::Cancelled, $actor, $source, $reason, ['cause' => 'cancellation'],
                AuditAction::TaskCancelled, ['status' => $from->value], ['status' => TaskStatus::Cancelled->value]);
        }
    }

    /**
     * @param  list<array{id: int}>  $appointments
     */
    private function stopAppointments(User $actor, array $appointments, string $reason, string $source): void
    {
        foreach ($appointments as $row) {
            $appointment = Appointment::query()->lockForUpdate()->findOrFail($row['id']);
            $members = $appointment->memberIds();
            $this->booking->lockMembers($members);
            $from = $appointment->status;
            $appointment->forceFill(['status' => AppointmentStatus::Cancelled, 'cancel_reason' => $reason, 'conflicted' => false, 'pending_change' => null]);
            $this->appointments->record($appointment, 'T06', $from, AppointmentStatus::Cancelled, $actor, $source, AuditAction::AppointmentCancelled, $reason,
                ['cause' => 'cancellation'], ['status' => $from->value], ['status' => AppointmentStatus::Cancelled->value]);
            if ($from === AppointmentStatus::Confirmed) {
                $this->booking->refreshMarkers($members, $appointment->starts_at, $appointment->ends_at);
            }
            $this->booking->notify($appointment, 'appointment.cancelled', $members);
        }
    }

    /**
     * @param  list<array{reservation_id: int, release_milli: int}>  $plan
     * @return list<string> released reservation numbers with quantities
     */
    private function releaseStock(User $actor, array $plan, string $reason, string $source): array
    {
        if ($plan === []) {
            return [];
        }
        $reservations = StockReservation::query()->whereIn('id', array_column($plan, 'reservation_id'))->get()->keyBy('id');
        $this->ledger->lockMany(array_values($reservations->map(fn (StockReservation $r) => [$r->location_id, $r->stock_item_id])->all()));
        $released = [];
        foreach ($plan as $row) {
            $reservation = $reservations->get($row['reservation_id']) ?? throw new LogicException('The reservation disappeared.');
            $this->release->handle($reservation, $actor, $reason, $source, $row['release_milli']);
            $released[] = $reservation->number.' ('.Quantity::format($row['release_milli']).')';
        }

        return $released;
    }

    /**
     * The re-priced agreement becomes the next version, decided by the cancellation itself (the customer's request
     * accepted by P-18, SRS-COM-14); the schedule follows it in the same transaction and the commission is re-derived.
     *
     * @param  array<string, mixed>  $content
     */
    private function reprice(User $actor, Order $order, OrderCancellation $cancellation, array $content, string $source): ?int
    {
        $decision = CustomerDecision::query()->create([
            'subject_type' => 'cancellation', 'subject_id' => $cancellation->id, 'order_id' => $order->id,
            'content_hash' => ContentHash::of($content), 'decision' => CustomerDecision::APPROVED,
            'method' => $cancellation->source === OrderCancellation::SOURCE_ACCOUNT ? CustomerDecision::METHOD_ACCOUNT : CustomerDecision::METHOD_EXTERNAL,
            'channel' => $cancellation->channel, 'decided_at' => now(), 'recorded_by' => $actor->id, 'customer_id' => $order->customer_id,
            'note' => mb_substr($cancellation->reason, 0, 1000), 'approval_key' => 'cancellation:'.$cancellation->id,
        ]);
        $this->activation->activate($order, $content, 'cancellation', $cancellation->id, $decision, $actor, $source);
        $head = FinanceLock::head($order);
        $this->schedule->sync($order, $head, $actor, $source);
        $entry = $this->commission->recompute($order, 'cancellation:'.$cancellation->number, $actor, $cancellation->reason);

        return $entry?->amount_minor;
    }

    /**
     * @param  list<string>  $released
     */
    private function moveOrder(User $actor, Order $order, OrderCancellation $cancellation, string $to, array $released, string $source): void
    {
        $from = $order->status;
        $context = ['cancellation' => $cancellation->number, 'scope' => $cancellation->scope, 'released' => $released];
        if (! $order->isReady() && $from !== CustomOrderStatus::CancellationPending->value) {
            // Recorded and accepted at once: the request step (M-04-T04) and its effect (T05) are both on the history.
            $this->transitions->record(StateMachine::M04, 'T04', $order, $from, CustomOrderStatus::CancellationPending, $actor, $source, $cancellation->reason, $context);
            $from = CustomOrderStatus::CancellationPending->value;
        }
        $order->status = $to;
        $order->lock_version++;
        $order->save();
        $this->transitions->record($order->kind->machine(), $order->isReady() ? 'T09' : 'T05', $order, $from, $to, $actor, $source, $cancellation->reason, $context);
    }

    /**
     * One follow-up per order with what the decision leaves (INT-08). Nothing is opened when nothing remains
     * (P03: «بلا اتفاق أو مال أو عمل يقبل ويقفل مباشرة دون تسويات وهمية»).
     *
     * @param  array<string, mixed>  $impact
     * @param  list<string>  $released
     */
    private function openSettlement(Order $order, OrderCancellation $cancellation, array $impact, array $released, ?int $commission): void
    {
        $source = 'cancellation:'.$cancellation->id;
        $refund = (int) $impact['settlement']['money']['refund_minor'];
        /** @var list<array{id: int, number: string}> $purchases */
        $purchases = $impact['settlement']['purchases'];
        if ($refund === 0 && $purchases === [] && $released === [] && $commission === null) {
            return;
        }
        if ($refund > 0) {
            $floor = (int) Refund::query()->where('order_id', $order->id)->max('id');
            $this->settlement->add($order, SettlementItem::REFUND, $source, ['cancellation' => $cancellation->number, 'refund_floor' => $floor], $refund);
        }
        foreach ($purchases as $po) {
            $this->settlement->add($order, SettlementItem::SUPPLIER, $source, ['number' => $po['number']], null, 'purchase_order', $po['id']);
        }
        if ($released !== []) {
            $this->settlement->add($order, SettlementItem::STOCK_RELEASE, $source, ['released' => $released], null, null, null, true);
        }
        if ($commission !== null) {
            $this->settlement->add($order, SettlementItem::COMMISSION, $source, ['cancellation' => $cancellation->number], $commission, null, null, true);
        }
        $this->settlement->sync($order);
    }
}
