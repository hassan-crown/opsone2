<?php

namespace App\Actions\StateMachines\M13PurchaseOrder;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseStep;
use Illuminate\Validation\ValidationException;

/**
 * M-13-T03 (P-46, FUL-07): cancel what has not been received («ملغى المتبقي») with a reason. Everything received and its
 * stock movements stay; a cancelled remainder accepts no further receipt. A draft is cancelled the same way.
 */
final class T03CancelRemainder
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, PurchaseOrder $po, string $reason, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseOrder
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('procurement.error.reason_required')]);
        }

        $this->write->run('po.cancel', $actor, $idempotencyKey, ['po' => $po->id, 'reason' => $reason, 'lock_version' => $lockVersion], function () use ($actor, $po, $reason, $lockVersion, $source) {
            $locked = PurchaseStep::lock($actor, $po, Permission::P46, $lockVersion, 'T03');
            $cancelled = $locked->lines->map(fn ($line) => ['line' => $line->line_no, 'cancelled_milli' => $line->remainingMilli(), 'kept_milli' => $line->accepted_milli])->all();
            $from = PurchaseStep::move($locked, PurchaseStatus::CancelledRemainder, ['cancel_reason' => $reason, 'cancelled_at' => now(), 'cancelled_by' => $actor->id]);
            $this->transitions->record(StateMachine::M13, 'T03', $locked, $from, PurchaseStatus::CancelledRemainder, $actor, $source, $reason, ['lines' => $cancelled]);
            Audit::record(AuditAction::PurchaseRemainderCancelled, $locked, $actor, after: ['number' => $locked->number, 'lines' => $cancelled],
                reason: $reason, source: $source, branchIds: [$locked->branch_id], label: $locked->number);

            return ['purchase_order_id' => $locked->id];
        });

        return $po->refresh();
    }
}
