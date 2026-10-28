<?php

namespace App\Orders;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Customers\CustomerAccess;
use App\Enums\CustomOrderStatus;
use App\Enums\OrderKind;
use App\Enums\OrganizationUnitStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Order;
use App\Models\PurchaseReference;
use App\Models\User;
use App\Support\GaplessSequence;
use App\Workflow\StageEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Shared body of M-04-T00 and M-05-T00: check the server-side facts (customer visible, contact of that customer,
 * active branch inside the P-15 grant, valid assignee), take the next gapless number, write the order and its
 * frozen lines, record the transition and the audit event, and start a custom order's flow — all in the caller's
 * transaction.
 */
final class OrderOpener
{
    public const SEQUENCE = 'orders';

    public function __construct(
        private readonly Authorizer $access,
        private readonly TransitionRecorder $transitions,
        private readonly GaplessSequence $sequence,
        private readonly OrderLineBuilder $lines,
        private readonly StageEngine $workflow,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function open(
        User $actor,
        OrderKind $kind,
        array $data,
        ?PurchaseReference $reference,
        string $source,
        string $errorPrefix = '',
    ): Order {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Orders are opened inside the caller\'s transaction.');
        }

        $initial = $kind === OrderKind::Custom ? CustomOrderStatus::InProgress : ReadyOrderStatus::Draft;
        $order = new Order([
            'kind' => $kind,
            'status' => $initial->value,
            'customer_id' => (int) $data['customer_id'],
            'branch_id' => (int) $data['branch_id'],
            'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : $actor->id,
            'created_by' => $actor->id,
            'purchase_reference_id' => $reference?->id,
            'need_summary' => $data['need_summary'] ?? null,
            'needs_measurement' => (bool) ($data['needs_measurement'] ?? false),
            'needs_design' => (bool) ($data['needs_design'] ?? false),
            'invoice_type' => $data['invoice_type'] ?? null,
        ]);

        // License (incl. `ready_goods` for the ready part), account, P-15 over the target branch.
        $this->access->authorize($actor, Permission::P15, $order);

        $customer = Customer::query()->find($order->customer_id);
        if ($customer === null || ! CustomerAccess::canViewCustomer($actor, $customer)) {
            throw ValidationException::withMessages(['customer_id' => __('orders.validation.customer_hidden')]);
        }
        if ($reference !== null && $reference->customer_id !== $customer->id) {
            throw ValidationException::withMessages(['purchase_reference_id' => __('orders.validation.reference_customer')]);
        }
        if (Branch::query()->whereKey($order->branch_id)->where('status', OrganizationUnitStatus::Active)->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => __('orders.validation.branch_inactive')]);
        }
        $order->contact_id = self::contactFor($customer, $data['contact_id'] ?? null);
        if (! $customer->isOrganization()) {
            $order->invoice_type = null;
        }
        if (! OrderAccess::isValidAssignee($order->assignee_id, $order)) {
            throw ValidationException::withMessages([$errorPrefix.'assignee_id' => __('orders.validation.assignee_invalid')]);
        }

        $order->number = $this->sequence->format(self::SEQUENCE, 'ORD');
        $order->save();

        foreach (array_values($data['lines'] ?? []) as $i => $input) {
            $this->lines->build($order, $input, $actor, $i + 1, $errorPrefix.'lines.'.$i)->save();
        }
        $order->total_minor = OrderLineBuilder::total($order, $errorPrefix.'lines');
        $order->save();

        $this->transitions->record($kind->machine(), 'T00', $order, null, $initial, $actor, $source, context: [
            'number' => $order->number, 'purchase_reference_id' => $reference?->id,
        ]);
        Audit::record(AuditAction::OrderCreated, $order, $actor, after: OrderPresenter::auditSnapshot($order), source: $source, label: $order->number);

        // A custom order pins the published flow version and starts its first stage (WF-01, WF-10).
        if ($kind === OrderKind::Custom) {
            $this->workflow->start($order, $actor, $source);
        }

        return $order;
    }

    /**
     * M-05-T00 from the store (ADR-0023): the customer proved their identity at checkout and chose the lines; no staff
     * member opens it, so there is no staff authorization here — the caller checked that the store is selling
     * (license `ready_goods`, StoreGate). The order opens in the store's receiving branch, followed by the basket
     * link's owner or the store handler. Inside the caller's transaction.
     *
     * @param  list<array{type: string, variant_id: int, quantity: string}>  $lines
     */
    public function openFromStore(Customer $customer, int $branchId, ?int $assigneeId, array $lines, string $source): Order
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Orders are opened inside the caller\'s transaction.');
        }
        if (Branch::query()->whereKey($branchId)->where('status', OrganizationUnitStatus::Active)->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => __('orders.validation.branch_inactive')]);
        }

        $order = new Order([
            'kind' => OrderKind::Ready,
            'status' => ReadyOrderStatus::Draft->value,
            'customer_id' => $customer->id,
            'branch_id' => $branchId,
            'assignee_id' => $assigneeId,
            'created_by' => null,
        ]);
        $order->contact_id = $customer->isOrganization() ? self::contactFor($customer, null) : null;
        $order->number = $this->sequence->format(self::SEQUENCE, 'ORD');
        $order->save();

        foreach ($lines as $i => $input) {
            $this->lines->build($order, $input, null, $i + 1, 'lines.'.$i)->save();
        }
        $order->total_minor = OrderLineBuilder::total($order, 'lines');
        $order->save();

        $this->transitions->record(OrderKind::Ready->machine(), 'T00', $order, null, ReadyOrderStatus::Draft, null, $source, context: [
            'number' => $order->number, 'channel' => 'store',
        ]);
        Audit::record(AuditAction::OrderCreated, $order, null, after: OrderPresenter::auditSnapshot($order), source: $source, label: $order->number);

        return $order;
    }

    /**
     * The order's contact person: only an active contact of this organization customer; its primary by default.
     */
    public static function contactFor(Customer $customer, mixed $contactId): ?int
    {
        if (! $customer->isOrganization()) {
            if ($contactId !== null) {
                throw ValidationException::withMessages(['contact_id' => __('orders.validation.contact_invalid')]);
            }

            return null;
        }

        $contacts = CustomerContact::query()->where('customer_id', $customer->id)->whereNull('removed_at');
        if ($contactId === null) {
            return $contacts->where('is_primary', true)->value('id');
        }

        $id = $contacts->whereKey((int) $contactId)->value('id');

        return $id ?? throw ValidationException::withMessages(['contact_id' => __('orders.validation.contact_invalid')]);
    }
}
