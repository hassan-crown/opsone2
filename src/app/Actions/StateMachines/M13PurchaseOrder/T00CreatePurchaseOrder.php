<?php

namespace App\Actions\StateMachines\M13PurchaseOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Models\FinanceSettings;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseLines;
use App\Support\GaplessSequence;
use Illuminate\Validation\ValidationException;

/**
 * M-13-T00 (P-46): a purchase order as a draft with its gapless number PO-YYYY-000123. The number is taken in the same
 * transaction as the order, so a failure anywhere gives it back. Costs are kept only for a writer with P-82.
 */
final class T00CreatePurchaseOrder
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly GaplessSequence $sequence,
        private readonly PurchaseLines $lines,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  {supplier_id, branch_id, location_id?, expected_on?, responsible_id?, notes?, lines}
     */
    public function handle(User $actor, array $data, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseOrder
    {
        $draft = new PurchaseOrder(['branch_id' => (int) $data['branch_id']]);
        Access::authorize($actor, Permission::P46, $draft);

        $result = $this->write->run('po.create', $actor, $idempotencyKey, $data, function () use ($actor, $data, $source) {
            $supplier = Supplier::query()->findOrFail((int) $data['supplier_id']);
            if (! $supplier->isActive()) {
                throw ValidationException::withMessages(['supplier_id' => __('procurement.error.supplier_disabled')]);
            }
            $settings = FinanceSettings::current();
            $po = PurchaseOrder::query()->create([
                'number' => $this->sequence->format('po', 'PO', 6),
                'supplier_id' => $supplier->id,
                'branch_id' => (int) $data['branch_id'],
                'location_id' => $data['location_id'] ?? null,
                'status' => PurchaseStatus::Draft,
                'expected_on' => $data['expected_on'] ?? null,
                'responsible_id' => $data['responsible_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'vat_rate_bp' => $settings->vat_rate_bp,
                'prices_include_vat' => $settings->prices_include_vat,
                'created_by' => $actor->id,
            ]);
            $this->lines->replace($po, $data['lines'], Access::can($actor, Permission::P82, $po));
            $this->transitions->record(StateMachine::M13, 'T00', $po, null, PurchaseStatus::Draft, $actor, $source);
            Audit::record(AuditAction::PurchaseCreated, $po, $actor, after: ['number' => $po->number, 'supplier' => $supplier->id, 'lines' => $po->lines()->count()],
                source: $source, branchIds: [$po->branch_id], label: $po->number);

            return ['purchase_order_id' => $po->id, 'number' => $po->number];
        });

        return PurchaseOrder::query()->findOrFail((int) $result['result']['purchase_order_id']);
    }
}
