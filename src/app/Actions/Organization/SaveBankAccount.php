<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Finance\BankTransfer\SaudiIban;
use App\Models\Facility;
use App\Models\FacilityBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-02: add or edit one of the facility's bank accounts (ADR-0046). Input is validated by `SaveBankAccountRequest`.
 * Afterwards exactly one active account is the default: the one just marked so, else the existing one, else the first
 * active account. A disabled account is never the default. Audited with the IBAN redacted and only its last four kept.
 */
final class SaveBankAccount
{
    private const FIELDS = ['bank_name', 'beneficiary_name', 'iban', 'account_number', 'swift_code', 'currency', 'transfer_note', 'is_default', 'is_active'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?FacilityBankAccount $account = null): FacilityBankAccount
    {
        $this->access->authorize($actor, Permission::P02, Facility::currentOrFail());

        return DB::transaction(function () use ($actor, $data, $account) {
            // One lock for every account: the default flag is shared state.
            FacilityBankAccount::query()->lockForUpdate()->get(['id']);
            $record = $account === null ? new FacilityBankAccount : FacilityBankAccount::query()->lockForUpdate()->findOrFail($account->id);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));
            $changes['iban'] = SaudiIban::normalize((string) ($changes['iban'] ?? $record->iban));
            foreach (['account_number', 'swift_code', 'transfer_note'] as $optional) {
                if (array_key_exists($optional, $changes) && trim((string) $changes[$optional]) === '') {
                    $changes[$optional] = null;
                }
            }
            if (isset($changes['swift_code'])) {
                $changes['swift_code'] = strtoupper((string) $changes['swift_code']);
            }

            $record->fill($changes);
            if (! $record->is_active) {
                $record->is_default = false;
            }
            $created = ! $record->exists;
            $changed = array_keys($record->getDirty());
            $before = $created ? [] : self::auditable(array_intersect_key($record->getOriginal(), array_flip($changed)));
            $record->updated_by = $actor->id;
            $record->save();

            self::settleDefault($record->is_default ? $record : null);
            $record->refresh();

            if ($created) {
                Audit::record(AuditAction::BankAccountCreated, $record, $actor, after: self::auditable($record->only(self::FIELDS)), label: $record->bank_name);
            } elseif ($changed !== []) {
                Audit::record(AuditAction::BankAccountUpdated, $record, $actor, before: $before, after: self::auditable($record->only($changed)), label: $record->bank_name);
            }

            return $record;
        });
    }

    /**
     * Keeps one active default: `$preferred` when given, else the current active default, else the first active account.
     */
    public static function settleDefault(?FacilityBankAccount $preferred = null): void
    {
        $default = $preferred
            ?? FacilityBankAccount::query()->where('is_active', true)->where('is_default', true)->orderBy('id')->first()
            ?? FacilityBankAccount::query()->where('is_active', true)->orderBy('id')->first();

        FacilityBankAccount::query()
            ->where('is_default', true)
            ->when($default !== null, fn ($q) => $q->whereKeyNot($default->id))
            ->update(['is_default' => false]);
        if ($default !== null && ! $default->is_default) {
            FacilityBankAccount::query()->whereKey($default->id)->update(['is_default' => true]);
        }
    }

    /**
     * The IBAN and account number are redacted; their last four digits say which account changed.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function auditable(array $values): array
    {
        // Keys named after the IBAN are redacted by the recorder, so its ending goes under `ending`.
        foreach (['iban' => 'ending', 'account_number' => 'account_ending'] as $field => $ending) {
            if (array_key_exists($field, $values)) {
                $value = (string) $values[$field];
                unset($values[$field]);
                $values[$ending] = $value === '' ? null : '…'.substr($value, -4);
            }
        }

        return $values;
    }
}
