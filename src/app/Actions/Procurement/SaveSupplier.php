<?php

namespace App\Actions\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Procurement\ProcurementWrite;
use App\Support\StaleRecord;

/**
 * P-45: create or edit a supplier profile (name in both languages, contact, VAT number, what they supply, notes).
 * Create is idempotent per key; edit checks the version the screen read.
 */
final class SaveSupplier
{
    public function __construct(private readonly ProcurementWrite $write) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, ?Supplier $supplier, array $data, ?int $lockVersion, ?string $idempotencyKey, string $source = TransitionRecorder::SOURCE_WEB): Supplier
    {
        Access::authorize($actor, Permission::P45, $supplier ?? new Supplier);

        $result = $this->write->run($supplier === null ? 'supplier.create' : 'supplier.update', $actor, $idempotencyKey, [...$data, 'supplier' => $supplier?->id, 'lock_version' => $lockVersion],
            function () use ($actor, $supplier, $data, $lockVersion, $source) {
                if ($supplier === null) {
                    $saved = Supplier::query()->create([...$data, 'created_by' => $actor->id]);
                    Audit::record(AuditAction::SupplierCreated, $saved, $actor, after: $saved->auditSnapshot(), source: $source, label: (string) $saved->name->get());

                    return ['supplier_id' => $saved->id];
                }
                $saved = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
                if ($lockVersion !== null && $saved->lock_version !== $lockVersion) {
                    throw new StaleRecord($saved->lock_version);
                }
                $before = $saved->auditSnapshot();
                $saved->forceFill([...$data, 'lock_version' => $saved->lock_version + 1])->save();
                Audit::record(AuditAction::SupplierUpdated, $saved, $actor, before: $before, after: $saved->auditSnapshot(), source: $source, label: (string) $saved->name->get());

                return ['supplier_id' => $saved->id];
            });

        return Supplier::query()->findOrFail((int) $result['result']['supplier_id']);
    }
}
