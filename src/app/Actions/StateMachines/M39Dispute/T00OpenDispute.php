<?php

namespace App\Actions\StateMachines\M39Dispute;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\DisputeStatus;
use App\Enums\DisputeSubject;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\MoneyLedger;
use App\Finance\Settlement\SettlementInput;
use App\Models\Finance\FinancialDispute;
use App\Models\Finance\OrderDue;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M-39 open (P-85, FinDispute, FIN-13): a dispute on the order and one money movement (a payment or a refund) or a
 * due, with subject, reason, amount and a responsible person. An amount above the movement needs an explanation. While
 * open it holds the due's disputed part from every reminder; it never moves money (`InvalidTransition` `no_movement`
 * when nothing is named).
 */
final class T00OpenDispute
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
            'payment_id' => ['nullable', 'integer'],
            'refund_id' => ['nullable', 'integer'],
            'due_id' => ['nullable', 'integer'],
            'subject' => ['required', 'in:'.implode(',', array_column(DisputeSubject::cases(), 'value'))],
            'amount' => ['required', 'string', 'max:20'],
            'amount_note' => ['nullable', 'string', 'max:500'],
            'responsible_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $order, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): FinancialDispute
    {
        /** @var array{payment_id?: int|null, refund_id?: int|null, due_id?: int|null, subject: string, amount: string, amount_note?: string|null, responsible_id: int, reason: string} $data */
        $data = Validator::make($data, self::rules())->validate();
        $amount = SettlementInput::money($data['amount'], 'amount');
        $reason = SettlementInput::reason($data['reason'], 'reason', 2000);

        $result = $this->write->run('dispute.open', $actor, $idempotencyKey, [...$data, 'order_id' => $order->id, 'lock_version' => $lockVersion], function () use ($actor, $order, $data, $amount, $reason, $lockVersion, $source) {
            $locked = FinanceLock::order($order->id, StateMachine::M39, 'T00');
            Access::authorize($actor, Permission::P85, $locked);
            $head = FinanceLock::head($locked);
            FinanceLock::checkVersion($head, $lockVersion);

            $payment = isset($data['payment_id']) ? Payment::query()->find($data['payment_id']) : null;
            $refund = isset($data['refund_id']) ? Refund::query()->where('order_id', $locked->id)->find($data['refund_id']) : null;
            $due = isset($data['due_id']) ? OrderDue::query()->where('order_id', $locked->id)->whereNull('removed_at')->find($data['due_id']) : null;
            if ((isset($data['payment_id']) && ($payment === null || ($payment->order_id !== $locked->id && MoneyLedger::placesOf($payment->id, $locked->id) === [])))
                || (isset($data['refund_id']) && $refund === null) || (isset($data['due_id']) && $due === null)) {
                throw ValidationException::withMessages(['payment_id' => __('disputes.validation.movement')]);
            }
            if ($payment === null && $refund === null && $due === null) {
                throw new InvalidTransition(StateMachine::M39, 'T00', 'no_movement');
            }
            $ceiling = $payment !== null ? $payment->amount_minor : ($refund !== null ? $refund->amount_minor : ($due !== null ? $due->amount_minor : 0));
            $note = trim((string) ($data['amount_note'] ?? '')) ?: null;
            if ($amount->minor > $ceiling && $note === null) {
                throw ValidationException::withMessages(['amount_note' => __('disputes.validation.amount_note')]);
            }
            if (! OrderAccess::isValidAssignee((int) $data['responsible_id'], $locked)) {
                throw ValidationException::withMessages(['responsible_id' => __('disputes.validation.responsible')]);
            }

            $dispute = FinancialDispute::query()->create([
                'order_id' => $locked->id, 'payment_id' => $payment?->id, 'refund_id' => $refund?->id, 'order_due_id' => $due?->id,
                'subject' => DisputeSubject::from($data['subject']), 'reason' => $reason, 'amount_minor' => $amount->minor,
                'amount_note' => $note, 'responsible_id' => (int) $data['responsible_id'], 'status' => DisputeStatus::Open,
                'opened_by' => $actor->id,
            ]);
            $this->transitions->record(StateMachine::M39, 'T00', $dispute, null, DisputeStatus::Open, $actor, $source, $reason);
            FinanceLock::touch($head);
            Audit::record(AuditAction::DisputeOpened, $locked, $actor, after: [
                'dispute' => $dispute->id, 'subject' => $data['subject'], 'amount_minor' => $amount->minor,
                'payment' => $payment?->id, 'refund' => $refund?->id, 'due' => $due?->id,
            ], reason: $reason, source: $source, label: $locked->number);

            return ['dispute_id' => $dispute->id];
        });

        return FinancialDispute::query()->findOrFail((int) $result['result']['dispute_id']);
    }
}
