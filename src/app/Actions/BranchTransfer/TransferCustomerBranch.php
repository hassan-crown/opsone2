<?php

namespace App\Actions\BranchTransfer;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Customer;
use App\Models\Tickets\Ticket;
use App\Models\User;
use App\Orders\BranchTransferImpact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Move a customer to another branch (COM-12, Q-29 option a): P-10 over the customer in both branches, a required
 * reason, a valid assignee in the target branch, one transaction and an audit event. The customer's orders keep
 * their own branches (each order moves through TransferOrderBranch).
 */
final class TransferCustomerBranch
{
    public function __construct(private readonly Authorizer $access) {}

    /**
     * @return array<string, mixed> the impact that was applied
     */
    public function handle(User $actor, Customer $customer, int $targetBranchId, string $reason, ?int $assigneeId, string $source = TransitionRecorder::SOURCE_WEB): array
    {
        $target = TransferOrderBranch::target($customer->branch_id, $targetBranchId);
        $this->access->authorize($actor, Permission::P10, $customer);
        $this->access->authorize($actor, Permission::P10, BranchTransferImpact::customerTarget($customer, $target->id));

        return DB::transaction(function () use ($actor, $customer, $target, $reason, $assigneeId, $source) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            if ($locked->branch_id !== $customer->branch_id) {
                throw ValidationException::withMessages(['branch_id' => __('orders.validation.customer_moved')]);
            }
            $this->access->authorize($actor, Permission::P10, $locked);
            $this->access->authorize($actor, Permission::P10, BranchTransferImpact::customerTarget($locked, $target->id));
            $impact = BranchTransferImpact::forCustomer($locked, $target);

            $fromBranch = $locked->branch_id;
            $fromAssignee = $locked->assignee_id;
            $newAssignee = $assigneeId ?? ($impact['assignee']['keeps_access'] ? $fromAssignee : null);
            if ($newAssignee !== null && ! BranchTransferImpact::isValidCustomerAssignee($newAssignee, BranchTransferImpact::customerIn($locked, $target->id))) {
                throw ValidationException::withMessages(['assignee_id' => __('orders.validation.transfer_assignee')]);
            }

            $locked->forceFill(['branch_id' => $target->id, 'assignee_id' => $newAssignee])->save();
            // General tickets (no order) are in the customer's branch.
            Ticket::query()->where('customer_id', $locked->id)->whereNull('order_id')->update(['branch_id' => $target->id]);

            Audit::record(
                AuditAction::CustomerBranchTransferred, $locked, $actor,
                before: ['branch_id' => $fromBranch, 'assignee_id' => $fromAssignee],
                after: ['branch_id' => $target->id, 'assignee_id' => $newAssignee, 'impact' => $impact],
                reason: $reason, source: $source, branchIds: [$fromBranch, $target->id],
            );

            return $impact;
        });
    }
}
