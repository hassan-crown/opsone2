<?php

namespace App\Actions\Finance;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\JournalKind;
use App\Enums\StateMachine;
use App\Finance\Allocator;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\MoneyLedger;
use App\Finance\OrderSchedule;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * FIN-06 review (P-38): the person changes how a payment's share on the order is spread over its dues. Never more
 * than the payment put on the order, never more than a due still lacks (other payments' money counted), never the
 * same money twice; what is not placed stays as the order's credit. Written as journals, nothing overwritten.
 */
final class ReallocatePayment
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly MoneyLedger $ledger,
        private readonly OrderSchedule $schedule,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'allocations' => ['present', 'array', 'max:'.OrderSchedule::MAX_DUES],
            'allocations.*.due_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'string', 'max:20'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Payment $payment, Order $order, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        /** @var array{allocations: list<array{due_id: int, amount: string}>} $data */
        $data = Validator::make($data, self::rules())->validate();
        $wanted = [];
        foreach ($data['allocations'] as $i => $row) {
            try {
                $amount = Money::fromInput(trim($row['amount']));
            } catch (\Throwable) {
                throw ValidationException::withMessages(["allocations.{$i}.amount" => __('finance.validation.amount')]);
            }
            if ($amount->isNegative()) {
                throw ValidationException::withMessages(["allocations.{$i}.amount" => __('finance.validation.amount')]);
            }
            $wanted[(int) $row['due_id']] = $amount->minor;
        }

        $this->write->run('payment.reallocate', $actor, $idempotencyKey, [...$data, 'payment_id' => $payment->id, 'order_id' => $order->id, 'lock_version' => $lockVersion], function () use ($actor, $payment, $order, $wanted, $lockVersion, $source) {
            $order = FinanceLock::order($order->id, StateMachine::M11, 'allocation');
            Access::authorize($actor, Permission::P38, $order);
            $head = FinanceLock::head($order);
            FinanceLock::checkVersion($head, $lockVersion);
            $this->schedule->sync($order, $head, $actor, $source);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $places = MoneyLedger::placesOf($payment->id, $order->id);
            $share = array_sum($places);
            if ($share <= 0) {
                throw ValidationException::withMessages(['allocations' => __('finance.validation.payment_not_on_order')]);
            }
            if (array_sum($wanted) > $share) {
                throw ValidationException::withMessages(['allocations' => __('finance.validation.allocation_exceeds_payment')]);
            }
            $dues = Allocator::openDues($order->id)->keyBy('id');
            $allocated = MoneyLedger::allocatedByDue($order->id);
            foreach ($wanted as $dueId => $amount) {
                $due = $dues->get($dueId);
                if ($due === null) {
                    throw ValidationException::withMessages(['allocations' => __('finance.validation.unknown_due')]);
                }
                $others = ($allocated[$dueId] ?? 0) - ($places[$dueId] ?? 0);
                if ($amount + $others > $due->amount_minor) {
                    throw ValidationException::withMessages(['allocations' => __('finance.validation.allocation_exceeds_due')]);
                }
            }

            $back = [];
            foreach ($places as $dueId => $held) {
                if ($dueId !== 0 && $held > 0) {
                    $back[] = ['order_id' => $order->id, 'due_id' => $dueId, 'amount' => -$held];
                }
            }
            if ($back !== []) {
                $back[] = ['order_id' => $order->id, 'due_id' => null, 'amount' => -array_sum(array_column($back, 'amount'))];
                $this->ledger->write(JournalKind::Deallocation, $payment, $back, $actor, $source, 'reallocation');
            }
            $forward = [];
            foreach ($wanted as $dueId => $amount) {
                $forward[] = ['order_id' => $order->id, 'due_id' => $dueId, 'amount' => $amount];
            }
            if (array_sum($wanted) > 0) {
                $forward[] = ['order_id' => $order->id, 'due_id' => null, 'amount' => -array_sum($wanted)];
                $this->ledger->write(JournalKind::Allocation, $payment, $forward, $actor, $source, 'reallocation');
            }
            FinanceLock::touch($head);
            Audit::record(AuditAction::PaymentReallocated, $order, $actor, before: ['places' => $places], after: [
                'payment' => $payment->id, 'places' => MoneyLedger::placesOf($payment->id, $order->id),
            ], source: $source, label: $order->number);

            return ['payment_id' => $payment->id];
        });
    }
}
