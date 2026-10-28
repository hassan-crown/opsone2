<?php

namespace App\Portal;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Agreements\CustomerActor;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Cancellation\CancellationImpact;
use App\Cancellation\CancellationInput;
use App\Enums\CancellationStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Order;
use App\Notifications\Notifier;
use App\Orders\OrderTransitions;
use App\Support\GaplessSequence;
use App\Support\LockingTransaction;
use App\Workflow\WorkGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The customer's own cancellation request (P-99, WEB-13, M-34): M-34-T01 from the account (source `customer_account`)
 * for the whole order with a reason, then the facility decides (T02/T04, impact review). The customer never cancels:
 * a custom order only becomes «cancellation pending» so no new irreversible work starts. One active request per order.
 * T05: the customer may withdraw it before the decision; the order returns to its previous state.
 */
final class CustomerCancellations
{
    public const NOTIFY_REQUESTED = 'portal.cancellation_requested';

    public function __construct(
        private readonly TransitionRecorder $transitions,
        private readonly GaplessSequence $numbers,
        private readonly Notifier $notifier,
    ) {}

    public function request(CustomerActor $customer, Order $order, string $reason): OrderCancellation
    {
        $reason = WorkGuard::reason($reason);
        $input = new CancellationInput(OrderCancellation::FULL);

        return LockingTransaction::run(function () use ($customer, $order, $reason, $input) {
            $order = OrderStock::lockOrder($order->id);
            self::ensureOwn($customer, $order);
            if (in_array($order->status, [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, ReadyOrderStatus::Delivered->value], true)) {
                throw new InvalidTransition(StateMachine::M34, 'T01', 'not_cancellable');
            }
            if ($order->status === CustomOrderStatus::CancellationPending->value
                || OrderCancellation::query()->where('active_key', $order->id)->exists()) {
                throw new InvalidTransition(StateMachine::M34, 'T01', 'request_pending');
            }
            // A request that could never apply is refused now (same check as a staff-recorded request).
            CancellationImpact::compute($order, $input);

            try {
                $cancellation = OrderCancellation::query()->create([
                    'number' => $this->numbers->format('cancellation', 'CN', 6), 'order_id' => $order->id,
                    'scope' => OrderCancellation::FULL, 'status' => CancellationStatus::Pending,
                    'source' => OrderCancellation::SOURCE_ACCOUNT, 'channel' => null, 'requested_on' => now()->toDateString(),
                    'reason' => $reason, 'lines' => null, 'retained_minor' => null, 'order_status_before' => $order->status,
                    'requested_by' => null, 'active_key' => $order->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new InvalidTransition(StateMachine::M34, 'T01', 'request_pending');
            }
            $this->transitions->record(StateMachine::M34, 'T01', $cancellation, null, CancellationStatus::Pending, null, PortalSession::SOURCE, $reason, [
                'order_id' => $order->id, 'scope' => OrderCancellation::FULL, 'source' => OrderCancellation::SOURCE_ACCOUNT,
            ]);
            if (! $order->isReady()) {
                OrderTransitions::check($order, 'T04');
                $from = $order->state();
                $order->status = CustomOrderStatus::CancellationPending->value;
                $order->lock_version++;
                $order->save();
                $this->transitions->record(StateMachine::M04, 'T04', $order, $from, CustomOrderStatus::CancellationPending, null, PortalSession::SOURCE, $reason, [
                    'cancellation' => $cancellation->number,
                ]);
            }
            Audit::record(AuditAction::CancellationRequested, $order, null, after: [
                'cancellation' => $cancellation->number, 'scope' => OrderCancellation::FULL, 'source' => OrderCancellation::SOURCE_ACCOUNT,
            ], reason: $reason, source: PortalSession::SOURCE, label: $order->number);

            $assignee = $order->assignee;
            if ($assignee !== null) {
                $this->notifier->send($assignee, self::NOTIFY_REQUESTED, ['order' => $order->number, 'number' => $cancellation->number],
                    $cancellation, "cancellation:{$cancellation->id}:requested");
            }

            return $cancellation;
        });
    }

    public function withdraw(CustomerActor $customer, OrderCancellation $cancellation): OrderCancellation
    {
        return LockingTransaction::run(function () use ($customer, $cancellation) {
            $order = OrderStock::lockOrder($cancellation->order_id);
            self::ensureOwn($customer, $order);
            $locked = OrderCancellation::query()->lockForUpdate()->findOrFail($cancellation->id);
            if ($locked->status !== CancellationStatus::Pending) {
                throw new InvalidTransition(StateMachine::M34, 'T05', 'not_from_'.$locked->status->value);
            }
            // Only a request the customer made from the account is theirs to withdraw; one staff recorded is not.
            if ($locked->source !== OrderCancellation::SOURCE_ACCOUNT) {
                throw new InvalidTransition(StateMachine::M34, 'T05', 'not_customer_request');
            }

            $locked->forceFill(['status' => CancellationStatus::Withdrawn, 'decided_at' => now(), 'active_key' => null, 'lock_version' => $locked->lock_version + 1])->save();
            $this->transitions->record(StateMachine::M34, 'T05', $locked, CancellationStatus::Pending, CancellationStatus::Withdrawn, null, PortalSession::SOURCE, null, ['order_id' => $order->id]);
            if ($order->status === CustomOrderStatus::CancellationPending->value) {
                $order->status = $locked->order_status_before;
                $order->lock_version++;
                $order->save();
                $this->transitions->record(StateMachine::M04, 'T06', $order, CustomOrderStatus::CancellationPending, $locked->order_status_before, null, PortalSession::SOURCE, null, [
                    'cancellation' => $locked->number,
                ]);
            }
            Audit::record(AuditAction::CancellationWithdrawn, $order, null, after: ['cancellation' => $locked->number, 'status' => $order->status],
                source: PortalSession::SOURCE, label: $order->number);

            return $locked;
        });
    }

    private static function ensureOwn(CustomerActor $customer, Order $order): void
    {
        if ($order->customer_id !== $customer->customerId) {
            throw new AuthorizationException;
        }
    }
}
