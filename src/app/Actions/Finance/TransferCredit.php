<?php

namespace App\Actions\Finance;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\JournalKind;
use App\Enums\StateMachine;
use App\Finance\Allocator;
use App\Finance\CollectionConditions;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\MoneyLedger;
use App\Finance\OrderSchedule;
use App\Finance\Settlement\RefundLimits;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * FinAllocate (P-38, FIN-03/06): moves unallocated credit of an order to another order of the same customer. No new
 * payment or receipt: the money keeps its original payment; the source order's refundable balance goes down and the
 * target's collected goes up, allocated there oldest due first.
 */
final class TransferCredit
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly MoneyLedger $ledger,
        private readonly OrderSchedule $schedule,
        private readonly Allocator $allocator,
        private readonly CollectionConditions $conditions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'to_order_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Order $from, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): void
    {
        /** @var array{to_order_id: int, amount: string, reason?: string|null} $data */
        $data = Validator::make($data, self::rules())->validate();
        try {
            $amount = Money::fromInput(trim($data['amount']));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        if ($amount->isNegative() || $amount->isZero()) {
            throw ValidationException::withMessages(['amount' => __('finance.validation.amount')]);
        }
        $toId = (int) $data['to_order_id'];
        if ($toId === $from->id) {
            throw ValidationException::withMessages(['to_order_id' => __('finance.validation.split_same_order')]);
        }

        $this->write->run('credit.transfer', $actor, $idempotencyKey, [...$data, 'from' => $from->id, 'lock_version' => $lockVersion], function () use ($actor, $from, $toId, $amount, $data, $lockVersion, $source) {
            if (Order::query()->whereKey($toId)->doesntExist()) {
                throw ValidationException::withMessages(['to_order_id' => __('finance.validation.unknown_order')]);
            }
            $orders = FinanceLock::orders([$from->id, $toId], StateMachine::M11, 'transfer');
            [$source0, $target] = [$orders[$from->id], $orders[$toId]];
            Access::authorize($actor, Permission::P38, $source0);
            Access::authorize($actor, Permission::P38, $target);
            if ($source0->customer_id !== $target->customer_id) {
                throw ValidationException::withMessages(['to_order_id' => __('finance.validation.split_other_customer')]);
            }
            $heads = [$from->id => FinanceLock::head($source0), $toId => FinanceLock::head($target)];
            FinanceLock::checkVersion($heads[$from->id], $lockVersion);
            $this->schedule->sync($source0, $heads[$from->id], $actor, $source);
            $this->schedule->sync($target, $heads[$toId], $actor, $source);

            // Credit reserved by a refund not yet executed stays for that refund (FIN-05), per payment: another
            // payment's free credit never makes a reserved payment's money movable.
            $free = RefundLimits::freeCreditByPayment($source0->id);
            if (array_sum($free) < $amount->minor) {
                throw new InvalidTransition(StateMachine::M11, 'transfer', 'not_enough_credit');
            }
            // Newest payment first: the oldest money stays where it was paid.
            $left = $amount->minor;
            $lastPayment = 0;
            foreach (array_reverse($free, true) as $paymentId => $credit) {
                if ($left === 0) {
                    break;
                }
                if ($credit === 0) {
                    continue;
                }
                $take = min($credit, $left);
                $this->ledger->write(JournalKind::Transfer, Payment::query()->findOrFail($paymentId), [
                    ['order_id' => $source0->id, 'due_id' => null, 'amount' => -$take],
                    ['order_id' => $target->id, 'due_id' => null, 'amount' => $take],
                ], $actor, $source, $data['reason'] ?? null);
                $left -= $take;
                $lastPayment = $paymentId;
            }

            $this->allocator->settle($target, $actor, $source);
            $this->conditions->evaluate($target, $lastPayment, $actor);
            FinanceLock::touch($heads[$from->id]);
            FinanceLock::touch($heads[$toId]);
            Audit::record(AuditAction::CreditTransferred, $source0, $actor, after: [
                'to_order' => $target->number, 'amount_minor' => $amount->minor,
            ], reason: $data['reason'] ?? null, source: $source, label: $source0->number);

            return ['to_order_id' => $target->id];
        });
    }
}
