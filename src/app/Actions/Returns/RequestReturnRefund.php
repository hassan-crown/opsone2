<?php

namespace App\Actions\Returns;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M15Return\T00RequestReturn;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Enums\StateMachine;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Returns\OrderReturn;
use App\Models\User;
use App\Money\Money;
use App\Returns\Contracts\ReturnRefunds;
use App\Returns\Contracts\SettlementFollowUp;
use App\Returns\ReturnLock;
use App\Returns\ReturnWrite;

/**
 * The money side of a return (P-42, INT-05, P04 → P05): asks one refund through the P6 refund flow for at most the
 * settlement fixed at acceptance. Under the order and return locks: a return has at most one live refund (requested,
 * in progress, unresolved or executed — `refund_exists`); a failed or cancelled one may be asked again. The M-12 request
 * itself reserves the amount from the payment's refundable limit (409 `not_refundable`), so the refund is created once
 * and limited twice: by the return's settlement and by the money actually received. Nothing is refunded here — P-43
 * executes it with proof (M-12-T02). The decision never depends on the inspection's stock classification.
 */
final class RequestReturnRefund
{
    public function __construct(
        private readonly ReturnWrite $write,
        private readonly ReturnRefunds $refunds,
        private readonly SettlementFollowUp $followUp,
    ) {}

    /**
     * @param  array{payment_id: int, amount_minor: int, method: string, reason: string}  $data
     */
    public function handle(User $actor, OrderReturn $return, array $data, ?int $lockVersion, string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        $result = $this->write->run('refund', $actor, $idempotencyKey, ['return' => $return->id, ...$data, 'lock_version' => $lockVersion], function () use ($actor, $return, $data, $lockVersion, $idempotencyKey, $source) {
            $locked = ReturnLock::lock($return, $lockVersion, null);
            Access::authorize($actor, Permission::P42, $locked);
            if (! $locked->status->holdsEligibility() || (int) $locked->settlement_minor <= 0) {
                throw new InvalidTransition(StateMachine::M15, 'refund', 'no_settlement');
            }
            $current = $locked->refund_id === null ? null : Refund::query()->find($locked->refund_id);
            if ($current !== null && ($current->status->reserves() || $current->status === RefundStatus::Executed)) {
                throw new InvalidTransition(StateMachine::M15, 'refund', 'refund_exists');
            }
            $amount = Money::of($data['amount_minor']);
            if ($amount->minor <= 0 || $amount->minor > (int) $locked->settlement_minor) {
                throw new InvalidTransition(StateMachine::M15, 'refund', 'above_settlement');
            }
            $payment = Payment::query()->find($data['payment_id']);
            if ($payment === null) {
                throw new InvalidTransition(StateMachine::M15, 'refund', 'payment_not_on_order');
            }
            $reason = T00RequestReturn::reason($data['reason'], 'reason');

            $refund = $this->refunds->request($actor, $locked, $payment, $amount, $data['method'], $locked->number.' — '.$reason, $idempotencyKey);
            $locked->forceFill(['refund_id' => $refund->id, 'lock_version' => $locked->lock_version + 1])->save();
            Audit::record(AuditAction::ReturnRefundRequested, $locked, $actor, after: ['number' => $locked->number, 'refund' => $refund->id, 'amount_minor' => $amount->minor], reason: $reason, source: $source, branchIds: [$locked->order->branch_id], label: $locked->number);
            if ($locked->status === ReturnStatus::Inspected) {
                $this->followUp->close($locked->order, 'order_return', $locked->id, $actor);
            }

            return ['refund_id' => $refund->id];
        });

        return Refund::query()->findOrFail((int) $result['result']['refund_id']);
    }
}
