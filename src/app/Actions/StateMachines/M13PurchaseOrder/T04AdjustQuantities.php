<?php

namespace App\Actions\StateMachines\M13PurchaseOrder;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\StateMachine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseAmounts;
use App\Procurement\PurchaseStep;
use App\Procurement\Quantity;
use Illuminate\Validation\ValidationException;

/**
 * M-13-T04 (P-46): change the ordered quantity of lines that are still open, with a reason — the explicit agreement an
 * over-supply needs before it is received. A line is never reduced below what was already accepted, and the unit
 * never changes. The state is re-derived (a reduction can complete the order).
 */
final class T04AdjustQuantities
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  list<array{line_id: int, quantity_milli: int}>  $changes
     */
    public function handle(User $actor, PurchaseOrder $po, array $changes, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseOrder
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('procurement.error.reason_required')]);
        }

        $this->write->run('po.adjust', $actor, $idempotencyKey, ['po' => $po->id, 'changes' => $changes, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $po, $changes, $reason, $lockVersion, $source) {
            $locked = PurchaseStep::lock($actor, $po, Permission::P46, $lockVersion, 'T04');
            $byId = $locked->lines->keyBy('id');
            $applied = [];
            foreach ($changes as $i => $change) {
                $line = $byId->get((int) $change['line_id']);
                $quantity = (int) $change['quantity_milli'];
                if ($line === null) {
                    throw ValidationException::withMessages(['changes.'.$i.'.line_id' => __('procurement.error.line_unknown')]);
                }
                if ($quantity < 1 || $quantity > Quantity::MAX_MILLI) {
                    throw ValidationException::withMessages(['changes.'.$i.'.quantity' => __('procurement.error.quantity')]);
                }
                if ($quantity < $line->accepted_milli) {
                    throw ValidationException::withMessages(['changes.'.$i.'.quantity' => __('procurement.error.below_received')]);
                }
                if ($quantity === $line->quantity_milli) {
                    continue;
                }
                $applied[] = ['line' => $line->line_no, 'from_milli' => $line->quantity_milli, 'to_milli' => $quantity];
                $line->forceFill(['quantity_milli' => $quantity])->save();
            }
            if ($applied === []) {
                throw ValidationException::withMessages(['changes' => __('procurement.error.no_change')]);
            }

            PurchaseAmounts::recalculate($locked);
            $to = PurchaseStep::derive($locked->lines()->get());
            $from = PurchaseStep::move($locked, $to);
            $this->transitions->record(StateMachine::M13, 'T04', $locked, $from, $to, $actor, $source, $reason, ['lines' => $applied]);
            Audit::record(AuditAction::PurchaseAdjusted, $locked, $actor, after: ['number' => $locked->number, 'lines' => $applied],
                reason: $reason, source: $source, branchIds: [$locked->branch_id], label: $locked->number);

            return ['purchase_order_id' => $locked->id];
        });

        return $po->refresh();
    }
}
