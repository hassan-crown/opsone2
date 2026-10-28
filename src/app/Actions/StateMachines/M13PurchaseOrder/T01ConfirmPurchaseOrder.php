<?php

namespace App\Actions\StateMachines\M13PurchaseOrder;

use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\PurchaseStatus;
use App\Enums\StateMachine;
use App\Models\FinanceSettings;
use App\Models\Procurement\PurchaseOrder;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Procurement\PurchaseAmounts;
use App\Procurement\PurchaseStep;
use Illuminate\Validation\ValidationException;

/**
 * M-13-T01 (P-46): confirm a draft («مطلوب»). Needs an active supplier, lines, a location, a date and a responsible
 * person. The facility VAT policy is captured again here so the confirmed amounts follow the policy of that day.
 */
final class T01ConfirmPurchaseOrder
{
    public function __construct(
        private readonly ProcurementWrite $write,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, PurchaseOrder $po, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): PurchaseOrder
    {
        $this->write->run('po.confirm', $actor, $idempotencyKey, ['po' => $po->id, 'lock_version' => $lockVersion], function () use ($actor, $po, $lockVersion, $source) {
            $locked = PurchaseStep::lock($actor, $po, Permission::P46, $lockVersion, 'T01');
            $missing = [];
            if (! $locked->supplier->isActive()) {
                $missing['supplier_id'] = __('procurement.error.supplier_disabled');
            }
            foreach (['location_id', 'expected_on', 'responsible_id'] as $field) {
                if ($locked->{$field} === null) {
                    $missing[$field] = __('procurement.error.confirm_incomplete');
                }
            }
            if ($locked->lines->isEmpty()) {
                $missing['lines'] = __('procurement.error.lines_required');
            }
            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $settings = FinanceSettings::current();
            $locked->forceFill(['vat_rate_bp' => $settings->vat_rate_bp, 'prices_include_vat' => $settings->prices_include_vat]);
            PurchaseAmounts::recalculate($locked);
            $from = PurchaseStep::move($locked, PurchaseStatus::Ordered, ['ordered_at' => now(), 'ordered_by' => $actor->id]);
            $this->transitions->record(StateMachine::M13, 'T01', $locked, $from, PurchaseStatus::Ordered, $actor, $source);
            Audit::record(AuditAction::PurchaseConfirmed, $locked, $actor, after: ['number' => $locked->number], source: $source, branchIds: [$locked->branch_id], label: $locked->number);

            return ['purchase_order_id' => $locked->id];
        });

        return $po->refresh();
    }
}
