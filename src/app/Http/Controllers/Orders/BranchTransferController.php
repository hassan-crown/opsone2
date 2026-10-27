<?php

namespace App\Http\Controllers\Orders;

use App\Access\Access;
use App\Access\Permission;
use App\Actions\BranchTransfer\TransferCustomerBranch;
use App\Actions\BranchTransfer\TransferOrderBranch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\BranchTransferRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Orders\BranchTransferImpact;
use App\Orders\OrderPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch transfer (COM-12, P-19, Q-29 option a): GET the impact review for a target branch, then POST to apply it
 * atomically with a reason. Orders need P-19, customers P-10, over both branches.
 */
class BranchTransferController extends Controller
{
    public function previewOrder(Request $request, Order $order): JsonResponse
    {
        $actor = $this->actor($request);
        $target = TransferOrderBranch::target($order->branch_id, $this->branchId($request));
        Access::authorize($actor, Permission::P19, $order);
        Access::authorize($actor, Permission::P19, $order->inBranch($target->id));

        return response()->json(['impact' => BranchTransferImpact::forOrder($order, $target), 'lock_version' => $order->lock_version]);
    }

    public function applyOrder(BranchTransferRequest $request, Order $order, TransferOrderBranch $transfer): JsonResponse
    {
        $actor = $this->actor($request);
        $impact = $transfer->handle(
            $actor, $order, (int) $request->validated('lock_version'), (int) $request->validated('branch_id'),
            (string) $request->validated('reason'), $request->validated('assignee_id') === null ? null : (int) $request->validated('assignee_id'),
        );
        $order->refresh();

        // The actor may no longer see the order in its new branch; then only the applied impact comes back.
        return response()->json([
            'impact' => $impact,
            'order' => Access::can($actor, Permission::P16, $order) ? OrderPresenter::detail($order, $actor) : null,
        ]);
    }

    public function previewCustomer(Request $request, Customer $customer): JsonResponse
    {
        $actor = $this->actor($request);
        $target = TransferOrderBranch::target($customer->branch_id, $this->branchId($request));
        Access::authorize($actor, Permission::P10, $customer);
        Access::authorize($actor, Permission::P10, BranchTransferImpact::customerTarget($customer, $target->id));

        return response()->json(['impact' => BranchTransferImpact::forCustomer($customer, $target)]);
    }

    public function applyCustomer(BranchTransferRequest $request, Customer $customer, TransferCustomerBranch $transfer): JsonResponse
    {
        $impact = $transfer->handle(
            $this->actor($request), $customer, (int) $request->validated('branch_id'), (string) $request->validated('reason'),
            $request->validated('assignee_id') === null ? null : (int) $request->validated('assignee_id'),
        );

        return response()->json(['impact' => $impact, 'customer' => ['id' => $customer->id, 'branch_id' => $customer->refresh()->branch_id]]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->validate(['branch_id' => ['required', 'integer']])['branch_id'];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
