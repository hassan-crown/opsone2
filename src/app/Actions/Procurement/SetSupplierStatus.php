<?php

namespace App\Actions\Procurement;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Procurement\Supplier;
use App\Models\User;
use App\Support\LockingTransaction;
use App\Support\StaleRecord;

/**
 * P-45: disable or re-enable a supplier. Disabling is soft: the profile and every purchase made from it stay; only
 * new purchase orders can no longer choose it. Repeating the call is harmless.
 */
final class SetSupplierStatus
{
    public function handle(User $actor, Supplier $supplier, bool $active, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Supplier
    {
        Access::authorize($actor, Permission::P45, $supplier);

        return LockingTransaction::run(function () use ($actor, $supplier, $active, $lockVersion, $source) {
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            if ($lockVersion !== null && $locked->lock_version !== $lockVersion) {
                throw new StaleRecord($locked->lock_version);
            }
            if ($locked->isActive() === $active) {
                return $locked;
            }
            $locked->forceFill([
                'status' => $active ? Supplier::ACTIVE : Supplier::DISABLED,
                'disabled_at' => $active ? null : now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();
            Audit::record($active ? AuditAction::SupplierEnabled : AuditAction::SupplierDisabled, $locked, $actor,
                after: ['status' => $locked->status], source: $source, label: (string) $locked->name->get());

            return $locked;
        });
    }
}
