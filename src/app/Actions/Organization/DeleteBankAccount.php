<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Facility;
use App\Models\FacilityBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-02: remove a bank account (ADR-0046). Documents already sent keep the account they were rendered with; the next
 * active account becomes the default. Audited.
 */
final class DeleteBankAccount
{
    public function __construct(private readonly Authorizer $access) {}

    public function handle(User $actor, FacilityBankAccount $account): void
    {
        $this->access->authorize($actor, Permission::P02, Facility::currentOrFail());

        DB::transaction(function () use ($actor, $account) {
            FacilityBankAccount::query()->lockForUpdate()->get(['id']);
            $record = FacilityBankAccount::query()->findOrFail($account->id);
            Audit::record(AuditAction::BankAccountDeleted, $record, $actor, before: SaveBankAccount::auditable($record->only([
                'bank_name', 'beneficiary_name', 'iban', 'currency', 'is_default', 'is_active',
            ])), label: $record->bank_name);
            $record->delete();
            SaveBankAccount::settleDefault();
        });
    }
}
