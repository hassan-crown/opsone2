<?php

namespace App\Actions\Settings;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\FinanceSettings;
use App\Models\User;
use App\Money\VatCalculator;
use App\Support\LockingTransaction;
use Illuminate\Support\Facades\Validator;

/**
 * SettingsSales money part (P-02, Q-35 a): VAT rate (basis points), whether entered prices include VAT, default quote
 * validity and whether contracts are used. Applies to drafts priced from now on; a sent document keeps its numbers.
 */
final class UpdateFinanceSettings
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'vat_rate_bp' => ['required', 'integer', 'min:0', 'max:'.VatCalculator::MAX_RATE_BP],
            'prices_include_vat' => ['required', 'boolean'],
            'quote_validity_days' => ['required', 'integer', 'min:1', 'max:365'],
            'contracts_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): FinanceSettings
    {
        Access::authorize($actor, Permission::P02);
        $data = Validator::make($data, self::rules())->validate();

        return LockingTransaction::run(function () use ($actor, $data, $lockVersion, $source) {
            $settings = FinanceSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new FinanceSettings(FinanceSettings::DEFAULTS);
            AgreementLock::checkVersion($settings->lock_version, $lockVersion);
            $before = $settings->only(array_keys(FinanceSettings::DEFAULTS));
            $settings->fill([...$data, 'updated_by' => $actor->id]);
            $settings->lock_version = $settings->exists ? $settings->lock_version + 1 : 1;
            $settings->save();

            Audit::record(AuditAction::FinanceSettingsUpdated, $settings, $actor, before: $before,
                after: $settings->only(array_keys(FinanceSettings::DEFAULTS)), source: $source);

            return $settings;
        });
    }
}
