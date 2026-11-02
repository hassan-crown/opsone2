<?php

namespace App\Actions\Finance;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrderKind;
use App\Enums\StateMachine;
use App\Files\AttachmentService;
use App\Finance\Allocator;
use App\Finance\DebtWriteOffs;
use App\Finance\FinanceLock;
use App\Finance\FinanceWrite;
use App\Finance\OrderSchedule;
use App\Models\Finance\DebtWriteOff;
use App\Models\Finance\DebtWriteOffEntry;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use App\Orders\OrderClosure;
use App\Support\GaplessSequence;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Debt write-off at closure (P-103, ADR-0035): the person forgives what the customer still owes on a finished or
 * cancelled made-to-order order, with a mandatory reason and an optional file. One balanced journal: each open due's
 * receivable is credited by what it still lacks and `bad_debt` is debited by the total; the amount the screen showed
 * must still be the amount owed (otherwise 422, nothing written). Collections stay what was really collected. A
 * write-off is undone only by `reverse()`: a reversal journal negating it once, after which the debt is owed again and
 * any credit on the order is allocated to it. Gapless `WO-YYYY-000123` for both.
 */
final class WriteOffDebt
{
    public function __construct(
        private readonly FinanceWrite $write,
        private readonly OrderSchedule $schedule,
        private readonly Allocator $allocator,
        private readonly GaplessSequence $numbers,
        private readonly AttachmentService $files,
    ) {}

    public function handle(User $actor, Order $order, int $amountMinor, string $reason, ?UploadedFile $file, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DebtWriteOff
    {
        $reason = self::reason($reason);
        $payload = ['order_id' => $order->id, 'amount_minor' => $amountMinor, 'reason' => $reason, 'lock_version' => $lockVersion, 'file' => $file === null ? null : hash_file('sha256', (string) $file->getRealPath())];

        $result = $this->write->run('write_off', $actor, $idempotencyKey, $payload, function () use ($actor, $order, $amountMinor, $reason, $file, $lockVersion, $source) {
            $order = $this->lock($order);
            Access::authorize($actor, Permission::P103, $order);
            $head = FinanceLock::head($order);
            FinanceLock::checkVersion($head, $lockVersion);
            if (! OrderClosure::closable($order)) {
                throw new InvalidTransition($order->kind->machine(), 'write_off', 'not_from_'.$order->status);
            }
            $this->schedule->sync($order, $head, $actor, $source);

            $owed = DebtWriteOffs::owedByDue($order);
            $total = array_sum($owed);
            if ($total <= 0) {
                throw ValidationException::withMessages(['amount' => __('finance.write_off.nothing_owed')]);
            }
            if ($total !== $amountMinor) {
                throw ValidationException::withMessages(['amount' => __('finance.write_off.amount_changed', ['amount' => Money::of($total)->toInput()])]);
            }

            $writeOff = DebtWriteOff::query()->create([
                'number' => $this->numbers->format('debt_write_off', 'WO', 6), 'order_id' => $order->id, 'kind' => DebtWriteOff::WRITE_OFF,
                'amount_minor' => $total, 'reason' => $reason, 'actor_user_id' => $actor->id, 'source' => $source, 'created_at' => now(),
            ]);
            $entries = [];
            foreach ($owed as $dueId => $amount) {
                $entries[] = ['due' => $dueId, 'account' => DebtWriteOffEntry::RECEIVABLE, 'amount' => -$amount];
            }
            $entries[] = ['due' => null, 'account' => DebtWriteOffEntry::BAD_DEBT, 'amount' => $total];
            $this->journal($writeOff, $entries);

            if ($file !== null) {
                $writeOff->setRelation('order', $order);
                $this->files->store($actor, $writeOff, $file, 'write_off_proof');
            }
            FinanceLock::touch($head);
            Audit::record(AuditAction::DebtWrittenOff, $order, $actor, before: ['owed_minor' => $total], after: [
                'number' => $writeOff->number, 'amount_minor' => $total, 'dues' => $owed, 'owed_minor' => 0, 'file' => $file !== null,
            ], reason: $reason, source: $source, label: $order->number);

            return ['id' => $writeOff->id];
        });

        return DebtWriteOff::query()->findOrFail((int) $result['result']['id']);
    }

    /**
     * The only way back: a reversal journal that negates the write-off exactly, once. The debt is owed again (and
     * blocks closure / shows in collections); credit already on the order settles it oldest due first.
     */
    public function reverse(User $actor, DebtWriteOff $writeOff, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): DebtWriteOff
    {
        $reason = self::reason($reason);
        $payload = ['write_off_id' => $writeOff->id, 'reason' => $reason, 'lock_version' => $lockVersion];

        $result = $this->write->run('write_off.reverse', $actor, $idempotencyKey, $payload, function () use ($actor, $writeOff, $reason, $lockVersion, $source) {
            $order = $this->lock($writeOff->order);
            Access::authorize($actor, Permission::P103, $order);
            $head = FinanceLock::head($order);
            FinanceLock::checkVersion($head, $lockVersion);
            $original = DebtWriteOff::query()->lockForUpdate()->findOrFail($writeOff->id);
            if ($original->order_id !== $order->id || ! DebtWriteOffs::reversible($original)) {
                throw new InvalidTransition(StateMachine::M11, 'write_off_reversal', $original->kind === DebtWriteOff::REVERSAL ? 'is_reversal' : 'already_reversed');
            }

            $reversal = DebtWriteOff::query()->create([
                'number' => $this->numbers->format('debt_write_off', 'WO', 6), 'order_id' => $order->id, 'kind' => DebtWriteOff::REVERSAL,
                'reverses_id' => $original->id, 'amount_minor' => $original->amount_minor, 'reason' => $reason,
                'actor_user_id' => $actor->id, 'source' => $source, 'created_at' => now(),
            ]);
            $negated = [];
            foreach (DebtWriteOffEntry::query()->where('debt_write_off_id', $original->id)->orderBy('id')->get() as $e) {
                $negated[] = ['due' => $e->order_due_id, 'account' => $e->account, 'amount' => -$e->amount_minor];
            }
            $this->journal($reversal, $negated);

            $this->allocator->settle($order, $actor, $source);
            FinanceLock::touch($head);
            Audit::record(AuditAction::DebtWriteOffReversed, $order, $actor, before: ['written_off' => $original->number], after: [
                'number' => $reversal->number, 'reverses' => $original->number, 'amount_minor' => $original->amount_minor,
            ], reason: $reason, source: $source, label: $order->number);

            return ['id' => $reversal->id];
        });

        return DebtWriteOff::query()->findOrFail((int) $result['result']['id']);
    }

    private function lock(Order $order): Order
    {
        // Same order row as payments, the engine and the agreement (FinanceLock); made-to-order only — ready orders
        // carry no dues.
        $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
        if ($locked->kind !== OrderKind::Custom) {
            throw new InvalidTransition($locked->kind->machine(), 'write_off', 'ready_order');
        }

        return $locked;
    }

    /**
     * @param  list<array{due: int|null, account: string, amount: int}>  $entries
     */
    private function journal(DebtWriteOff $writeOff, array $entries): void
    {
        if (array_sum(array_column($entries, 'amount')) !== 0) {
            throw new LogicException('A write-off journal must balance.');
        }
        foreach ($entries as $e) {
            DebtWriteOffEntry::query()->create([
                'debt_write_off_id' => $writeOff->id, 'order_id' => $writeOff->order_id, 'order_due_id' => $e['due'],
                'account' => $e['account'], 'amount_minor' => $e['amount'], 'created_at' => $writeOff->created_at,
            ]);
        }
        if (DebtWriteOffs::writtenOff($writeOff->order_id) < 0) {
            throw new LogicException('A due cannot be written off below zero.');
        }
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => __('finance.write_off.reason_required')]);
        }

        return $reason;
    }
}
