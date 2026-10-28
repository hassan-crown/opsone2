<?php

namespace App\Actions\StateMachines\M12Refund;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\RefundLimits;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * M-12 request (P-42, FinRefund): reason, amount and the original payment. The request alone reduces nothing; it
 * reserves its amount from the payment's refundable limit at once, under the order lock — two requests (or a request
 * and a retry) can never exceed it together (FIN-05, scenario 9: 300 reserved, 800 of 1,000 refused). Refused without
 * effect: `not_refundable` (above the limit, or a payment that is not confirmed money).
 */
final class T00RequestRefund
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'payment_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', 'in:'.implode(',', PaymentMethod::manual())],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Refund
    {
        /** @var array{payment_id: int, amount: string, method: string, reason: string} $data */
        $data = Validator::make($data, self::rules())->validate();
        $amount = SettlementInput::money($data['amount'], 'amount');
        $reason = SettlementInput::reason($data['reason'], 'reason', 1000);

        $result = $this->write->run('refund.request', $actor, $idempotencyKey, [...$data, 'order_id' => $order->id, 'lock_version' => $lockVersion], function () use ($actor, $order, $data, $amount, $reason, $lockVersion, $source) {
            $locked = FinanceLock::order($order->id, StateMachine::M12, 'T00');
            Access::authorize($actor, Permission::P42, $locked);
            $head = FinanceLock::head($locked);
            FinanceLock::checkVersion($head, $lockVersion);

            $payment = Payment::query()->find((int) $data['payment_id']);
            if ($payment === null || $payment->customer_id !== $locked->customer_id || ($payment->order_id !== $locked->id && MoneyLedger::placesOf($payment->id, $locked->id) === [])) {
                throw new InvalidTransition(StateMachine::M12, 'T00', 'payment_not_on_order');
            }
            if (RefundLimits::refundable($payment, $locked->id)->compare($amount) < 0) {
                throw new InvalidTransition(StateMachine::M12, 'T00', 'not_refundable');
            }

            $refund = Refund::query()->create([
                'order_id' => $locked->id,
                'payment_id' => $payment->id,
                'customer_id' => $locked->customer_id,
                'amount_minor' => $amount->minor,
                'method' => PaymentMethod::from($data['method']),
                'reason' => $reason,
                'status' => RefundStatus::Requested,
                'requested_by' => $actor->id,
            ]);
            $this->transitions->record(StateMachine::M12, 'T00', $refund, null, RefundStatus::Requested, $actor, $source, $reason);
            FinanceLock::touch($head);
            Audit::record(AuditAction::RefundRequested, $locked, $actor, after: [
                'refund' => $refund->id, 'payment' => $payment->id, 'amount_minor' => $amount->minor,
            ], reason: $reason, source: $source, label: $locked->number);

            return ['refund_id' => $refund->id];
        });

        return Refund::query()->findOrFail((int) $result['result']['refund_id']);
    }
}
