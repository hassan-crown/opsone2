<?php

namespace App\Actions\Procurement;

use App\Access\Access;
use App\Access\Exceptions\InvalidTransition;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseLines;
use App\Support\StaleRecord;
use Illuminate\Validation\ValidationException;

/**
 * P-46: edit a purchase order while it is still a draft (header and lines). Optimistic lock on `lock_version`; the
 * order's state does not change, so no transition is logged — the audit trail keeps before and after.
 */
final class UpdateDraftPurchase
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly PurchaseLines $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {supplier_id, branch_id, location_id?, expected_on?, responsible_id?, notes?, lines}
     */
    public function handle(User $actor, PurchaseOrder $po, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseOrder
    {
        $this->write->run('po.update', $actor, $idempotencyKey, [...$data, 'po' => $po->id, 'lock_version' => $lockVersion], function () use ($actor, $po, $data, $lockVersion, $source) {
            $fresh = PurchaseOrder::query()->lockForUpdate()->findOrFail($po->id);
            Access::authorize($actor, Permission::P46, $fresh);
            if ($lockVersion !== null && $fresh->lock_version !== $lockVersion) {
                throw new StaleRecord($fresh->lock_version);
            }
            if ($fresh->status !== PurchaseStatus::Draft) {
                throw new InvalidTransition(StateMachine::M13, 'T00', 'not_draft');
            }
            Access::authorize($actor, Permission::P46, new PurchaseOrder(['branch_id' => (int) $data['branch_id']]));
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
            if (! $supplier->isActive() && $supplier->id !== $fresh->supplier_id) {
                throw ValidationException::withMessages(['supplier_id' => __('procurement.error.supplier_disabled')]);
            }
            $before = ['supplier' => $fresh->supplier_id, 'branch' => $fresh->branch_id, 'lines' => $fresh->lines()->count()];
            $fresh->forceFill([
                'supplier_id' => $supplier->id,
                'branch_id' => (int) $data['branch_id'],
                'location_id' => $data['location_id'] ?? null,
                'expected_on' => $data['expected_on'] ?? null,
                'responsible_id' => $data['responsible_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $this->lines->replace($fresh, $data['lines'], Access::can($actor, Permission::P82, $fresh));
            Audit::record(AuditAction::PurchaseUpdated, $fresh, $actor, before: $before,
                after: ['supplier' => $fresh->supplier_id, 'branch' => $fresh->branch_id, 'lines' => $fresh->lines()->count()],
                source: $source, branchIds: [$fresh->branch_id], label: $fresh->number);

            return ['purchase_order_id' => $fresh->id];
        });

        return $po->refresh();
    }
}
