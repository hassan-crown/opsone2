<?php

namespace App\Actions\Commissions;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Agreements\AgreementLock;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Commissions\CommissionRate;
use App\Models\Commissions\CommissionSettings;
use App\Models\User;
use App\Support\LockingTransaction;
use Illuminate\Support\Facades\Validator;

/**
 * SettingsSales commission part (SAL-10, SAL-19, P-95): on/off, the default rate, optional rates per role template
 * and per member (empty = none), and whether hand-typed quote lines can be commissionable. Applies to sales pinned
 * from now on; a pinned sale keeps its rate.
 */
final class UpdateCommissionSettings
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'default_rate_bp' => ['required', 'integer', 'min:0', 'max:10000'],
            'manual_lines_eligible' => ['required', 'boolean'],
            'roles' => ['present', 'array', 'max:100'],
            'roles.*.role_template_id' => ['required', 'integer', 'distinct', 'exists:role_templates,id'],
            'roles.*.rate_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'members' => ['present', 'array', 'max:500'],
            'members.*.user_id' => ['required', 'integer', 'distinct', 'exists:memberships,user_id'],
            'members.*.rate_bp' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ];
    }

    public static function canEdit(User $user): bool
    {
        return Access::can($user, Permission::P95);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, array $data, ?int $lockVersion, string $source = TransitionRecorder::SOURCE_WEB): CommissionSettings
    {
        Access::authorize($actor, Permission::P95);
        /** @var array{enabled: bool, default_rate_bp: int, manual_lines_eligible: bool, roles: list<array<string, int|null>>, members: list<array<string, int|null>>} $data */
        $data = Validator::make($data, self::rules())->validate();

        return LockingTransaction::run(function () use ($actor, $data, $lockVersion, $source) {
            $settings = CommissionSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new CommissionSettings(CommissionSettings::DEFAULTS);
            AgreementLock::checkVersion($settings->lock_version, $lockVersion);
            $before = [...$settings->only(array_keys(CommissionSettings::DEFAULTS)), 'rates' => self::rates()];
            $settings->fill([
                'enabled' => (bool) $data['enabled'], 'default_rate_bp' => (int) $data['default_rate_bp'],
                'manual_lines_eligible' => (bool) $data['manual_lines_eligible'], 'updated_by' => $actor->id,
            ]);
            $settings->lock_version = $settings->exists ? $settings->lock_version + 1 : 1;
            $settings->save();

            foreach ([['roles', 'role_template_id'], ['members', 'user_id']] as [$list, $column]) {
                foreach ($data[$list] as $row) {
                    $rate = $row['rate_bp'] ?? null;
                    $id = (int) ($row[$column] ?? 0);
                    if ($rate === null) {
                        CommissionRate::query()->where($column, $id)->delete();
                    } else {
                        CommissionRate::query()->updateOrCreate([$column => $id], ['rate_bp' => (int) $rate]);
                    }
                }
            }

            Audit::record(AuditAction::CommissionSettingsUpdated, $settings, $actor, before: $before,
                after: [...$settings->only(array_keys(CommissionSettings::DEFAULTS)), 'rates' => self::rates()], source: $source);

            return $settings;
        });
    }

    /**
     * @return array{roles: array<int, int>, members: array<int, int>}
     */
    private static function rates(): array
    {
        $rows = CommissionRate::query()->get();

        return [
            'roles' => $rows->whereNotNull('role_template_id')->mapWithKeys(fn (CommissionRate $r) => [(int) $r->role_template_id => $r->rate_bp])->all(),
            'members' => $rows->whereNotNull('user_id')->mapWithKeys(fn (CommissionRate $r) => [(int) $r->user_id => $r->rate_bp])->all(),
        ];
    }
}
