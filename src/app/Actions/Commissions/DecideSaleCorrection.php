<?php

namespace App\Actions\Commissions;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Commissions\CommissionAccrual;
use App\Enums\StateMachine;
use App\Finance\FinanceLock;
use App\Models\Commissions\OrderSale;
use App\Models\Commissions\SaleCorrection;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects a sale correction (SAL-16, M-33-T05) — a financial decision (P-97) by someone who neither
 * asked for it nor gains or loses by it. Approval supersedes the sale with a new row (new owner or rate, the same
 * pinning date and agreement), moves its accrual with a `transfer_out` / `transfer_in` pair and re-derives the new
 * sale: no double commission, nothing paid is erased — a member paid before the move carries the difference.
 */
final class DecideSaleCorrection
{
    public function __construct(private readonly CommissionAccrual $accrual) {}

    public function approve(User $actor, SaleCorrection $correction, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): SaleCorrection
    {
        return LockingTransaction::run(function () use ($actor, $correction, $lockVersion, $source) {
            $fresh = $this->lock($actor, $correction, $lockVersion, 'approve');
            $old = OrderSale::query()->lockForUpdate()->findOrFail($fresh->order_sale_id);
            if (! $old->isActive()) {
                throw new InvalidTransition(StateMachine::M33, 'T05', 'sale_changed');
            }
            $old->forceFill(['status' => OrderSale::SUPERSEDED, 'active_key' => null])->save();
            $new = OrderSale::query()->create([
                'order_id' => $old->order_id, 'user_id' => $fresh->to_user_id, 'rate_bp' => $fresh->to_rate_bp,
                'rate_source' => $fresh->kind === SaleCorrection::RATE ? 'corrected' : $old->rate_source,
                'manual_lines_eligible' => $old->manual_lines_eligible,
                'owner_source' => $fresh->kind === SaleCorrection::OWNER ? 'correction' : $old->owner_source,
                'agreement_id' => $old->agreement_id, 'status' => OrderSale::ACTIVE, 'active_key' => $old->order_id,
                'replaces_id' => $old->id, 'pinned_at' => $old->pinned_at,
            ]);
            $new->setRelation('order', $fresh->order);
            $this->accrual->transfer($old, $new, 'sale_correction:'.$fresh->id, $actor, $fresh->reason);

            $fresh->forceFill([
                'status' => SaleCorrection::APPROVED, 'pending_key' => null, 'decided_by' => $actor->id, 'decided_at' => now(),
                'result_sale_id' => $new->id, 'lock_version' => $fresh->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::SaleCorrectionApproved, $fresh->order, $actor,
                before: ['owner' => $old->user_id, 'rate_bp' => $old->rate_bp],
                after: ['owner' => $new->user_id, 'rate_bp' => $new->rate_bp, 'correction' => $fresh->id],
                reason: $fresh->reason, source: $source, label: $fresh->order->number);

            return $fresh;
        });
    }

    public function reject(User $actor, SaleCorrection $correction, string $reason, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): SaleCorrection
    {
        $reason = self::reason($reason);

        return LockingTransaction::run(function () use ($actor, $correction, $reason, $lockVersion, $source) {
            $fresh = $this->lock($actor, $correction, $lockVersion, 'reject');
            $fresh->forceFill([
                'status' => SaleCorrection::REJECTED, 'pending_key' => null, 'decided_by' => $actor->id, 'decided_at' => now(),
                'decision_reason' => $reason, 'lock_version' => $fresh->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::SaleCorrectionRejected, $fresh->order, $actor, after: ['correction' => $fresh->id],
                reason: $reason, source: $source, label: $fresh->order->number);

            return $fresh;
        });
    }

    /**
     * The requester withdraws their own open request.
     */
    public function cancel(User $actor, SaleCorrection $correction, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): SaleCorrection
    {
        return LockingTransaction::run(function () use ($actor, $correction, $lockVersion, $source) {
            $order = FinanceLock::order($correction->order_id, StateMachine::M33, 'T05');
            $fresh = $this->fresh($correction, $lockVersion);
            if ($fresh->requested_by !== $actor->id) {
                abort(403);
            }
            $fresh->forceFill([
                'status' => SaleCorrection::CANCELLED, 'pending_key' => null, 'decided_by' => $actor->id, 'decided_at' => now(),
                'lock_version' => $fresh->lock_version + 1,
            ])->save();
            Audit::record(AuditAction::SaleCorrectionCancelled, $order, $actor, after: ['correction' => $fresh->id], source: $source, label: $order->number);

            return $fresh;
        });
    }

    private function lock(User $actor, SaleCorrection $correction, ?int $lockVersion, string $step): SaleCorrection
    {
        $order = FinanceLock::order($correction->order_id, StateMachine::M33, 'T05');
        $fresh = $this->fresh($correction, $lockVersion);
        $fresh->setRelation('order', $order);
        // Scoped like the request: over the sale, whose `own` is its member (ADR-0016), not the order's creator.
        $sale = $fresh->sale()->firstOrFail();
        $sale->setRelation('order', $order);
        Access::authorize($actor, Permission::P97, $sale);
        if ($fresh->requested_by === $actor->id) {
            throw new InvalidTransition(StateMachine::M33, 'T05', 'own_request');
        }
        if (in_array($actor->id, $fresh->affectedUserIds(), true)) {
            throw new InvalidTransition(StateMachine::M33, 'T05', 'own_sale');
        }

        return $fresh;
    }

    private function fresh(SaleCorrection $correction, ?int $lockVersion): SaleCorrection
    {
        $fresh = SaleCorrection::query()->lockForUpdate()->findOrFail($correction->id);
        if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
            throw new StaleRecord($fresh->lock_version);
        }
        if ($fresh->status !== SaleCorrection::PENDING) {
            throw new InvalidTransition(StateMachine::M33, 'T05', 'not_from_'.$fresh->status);
        }

        return $fresh;
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('commissions.validation.reason')]);
        }

        return $reason;
    }
}
