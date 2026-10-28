<?php

namespace App\Actions\Orders;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrderKind;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Orders\OrderLock;
use App\Orders\OrderOpener;
use App\Orders\OrderPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-17: change the need, the measurement/design flags, the preferred invoice type, the assignee or the contact of an
 * order that is still editable. Branch moves go through TransferOrderBranch, never through this form.
 */
final class UpdateOrder
{
    public const FIELDS = ['need_summary', 'needs_measurement', 'needs_design', 'invoice_type', 'assignee_id', 'contact_id'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data  validated; only keys present are changed
     */
    public function handle(User $actor, Order $order, int $lockVersion, array $data, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        $this->access->authorize($actor, Permission::P17, $order);

        return DB::transaction(function () use ($actor, $order, $lockVersion, $data, $source) {
            $locked = OrderLock::fresh($order, $lockVersion);
            $this->access->authorize($actor, Permission::P17, $locked);
            $before = OrderPresenter::auditSnapshot($locked);

            $changes = array_intersect_key($data, array_flip(self::FIELDS));
            if ($locked->kind === OrderKind::Ready) {
                unset($changes['need_summary'], $changes['needs_measurement'], $changes['needs_design']);
            }
            $customer = $locked->customer;
            if (array_key_exists('contact_id', $changes)) {
                $changes['contact_id'] = OrderOpener::contactFor($customer, $changes['contact_id']);
            }
            if (array_key_exists('invoice_type', $changes) && ! $customer->isOrganization()) {
                $changes['invoice_type'] = null;
            }
            $locked->fill($changes);
            if ($locked->isDirty('assignee_id') && ! OrderAccess::isValidAssignee($locked->assignee_id, $locked)) {
                throw ValidationException::withMessages(['assignee_id' => __('orders.validation.assignee_invalid')]);
            }
            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->lock_version++;
            $locked->save();
            Audit::record(AuditAction::OrderUpdated, $locked, $actor, before: $before, after: OrderPresenter::auditSnapshot($locked), source: $source, label: $locked->number);

            return $locked;
        });
    }
}
