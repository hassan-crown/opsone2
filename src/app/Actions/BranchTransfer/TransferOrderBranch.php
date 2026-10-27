<?php

namespace App\Actions\BranchTransfer;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Orders\BranchTransferImpact;
use App\Orders\OrderAccess;
use App\Orders\OrderLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-19 (COM-12, Q-29 option a): move an order to another branch in ONE transaction, with a required reason and the
 * impact reviewed again at apply time. Both branches must be inside the actor's P-19 grant. When the current
 * assignee would lose access in the target branch a new valid assignee is required. No intermediate state: either
 * everything moves (branch, assignee, version, audit) or nothing does.
 */
final class TransferOrderBranch
{
    public function __construct(private readonly Authorizer $access) {}

    /**
     * @return array<string, mixed> the impact that was applied
     */
    public function handle(User $actor, Order $order, int $lockVersion, int $targetBranchId, string $reason, ?int $assigneeId, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        $target = self::target($order->branch_id, $targetBranchId);
        $this->access->authorize($actor, Permission::P19, $order);
        $this->access->authorize($actor, Permission::P19, $order->inBranch($target->id));

        return DB::transaction(function () use ($actor, $order, $lockVersion, $target, $reason, $assigneeId, $source) {
            $locked = OrderLock::fresh($order, $lockVersion);
            $this->access->authorize($actor, Permission::P19, $locked);
            $impact = BranchTransferImpact::forOrder($locked, $target);

            $fromBranch = $locked->branch_id;
            $fromAssignee = $locked->assignee_id;
            $moved = $locked->inBranch($target->id);
            $newAssignee = $assigneeId ?? ($impact['assignee']['keeps_access'] ? $fromAssignee : null);
            if (! OrderAccess::isValidAssignee($newAssignee, $moved)) {
                throw ValidationException::withMessages(['assignee_id' => __('orders.validation.transfer_assignee')]);
            }

            $locked->forceFill([
                'branch_id' => $target->id,
                'assignee_id' => $newAssignee,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            Audit::record(
                AuditAction::OrderBranchTransferred, $locked, $actor,
                before: ['branch_id' => $fromBranch, 'assignee_id' => $fromAssignee],
                after: ['branch_id' => $target->id, 'assignee_id' => $newAssignee, 'impact' => $impact],
                reason: $reason, source: $source, branchIds: [$fromBranch, $target->id], label: $locked->number,
            );

            return $impact;
        });
    }

    public static function target(int $currentBranchId, int $targetBranchId): Branch
    {
        $target = Branch::query()->whereKey($targetBranchId)->where('status', OrganizationUnitStatus::Active)->first();
        if ($target === null) {
            throw ValidationException::withMessages(['branch_id' => __('orders.validation.branch_inactive')]);
        }
        if ($target->id === $currentBranchId) {
            throw ValidationException::withMessages(['branch_id' => __('orders.validation.same_branch')]);
        }

        return $target;
    }
}
