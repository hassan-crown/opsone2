<?php

namespace App\Store;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrderKind;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Store\StoreSettings;
use App\Models\User;
use App\Orders\OrderAccess;
use App\Support\StaleRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * StoreSettings (P-02, `ready_goods`): open or close the store, the receiving branch, the member following online
 * orders, the reservation window (WEB-06, 30 minutes by default) and the basket-link validity (SAL-02: 24 hours by
 * default, at most 7 days).
 */
final class StoreSetup
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, array $data, ?int $lockVersion): StoreSettings
    {
        Access::authorize($actor, Permission::P02);
        /** @var array{enabled: bool, branch_id?: int|null, assignee_id?: int|null, reservation_minutes: int, link_hours_default: int, link_days_max: int} $data */
        $data = Validator::make($data, [
            'enabled' => ['required', 'boolean'],
            'branch_id' => ['nullable', 'required_if:enabled,true', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'reservation_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'link_hours_default' => ['required', 'integer', 'min:1', 'max:168'],
            'link_days_max' => ['required', 'integer', 'min:1', 'max:7'],
        ])->validate();
        if ($data['link_hours_default'] > $data['link_days_max'] * 24) {
            throw ValidationException::withMessages(['link_hours_default' => __('store.settings.validation.link_hours')]);
        }
        if (isset($data['branch_id']) && Branch::query()->whereKey($data['branch_id'])->where('status', OrganizationUnitStatus::Active)->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => __('orders.validation.branch_inactive')]);
        }

        return DB::transaction(function () use ($actor, $data, $lockVersion) {
            $settings = StoreSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new StoreSettings;
            if ($settings->exists && $lockVersion !== null && $settings->lock_version !== $lockVersion) {
                throw new StaleRecord($settings->lock_version);
            }
            $probe = new Order(['kind' => OrderKind::Ready, 'branch_id' => $data['branch_id'] ?? null]);
            if (isset($data['assignee_id']) && ! OrderAccess::isValidAssignee((int) $data['assignee_id'], $probe)) {
                throw ValidationException::withMessages(['assignee_id' => __('orders.validation.assignee_invalid')]);
            }
            $before = $settings->exists ? $settings->only(['enabled', 'branch_id', 'assignee_id', 'reservation_minutes', 'link_hours_default', 'link_days_max']) : [];
            $settings->fill([
                'enabled' => $data['enabled'],
                'branch_id' => $data['branch_id'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'reservation_minutes' => $data['reservation_minutes'],
                'link_hours_default' => $data['link_hours_default'],
                'link_days_max' => $data['link_days_max'],
                'updated_by' => $actor->id,
            ]);
            $settings->lock_version = $settings->exists ? $settings->lock_version + 1 : 1;
            $settings->save();
            Audit::record(AuditAction::StoreSettingsUpdated, $settings, $actor, before: $before,
                after: $settings->only(['enabled', 'branch_id', 'assignee_id', 'reservation_minutes', 'link_hours_default', 'link_days_max']), label: 'Store');

            return $settings;
        });
    }
}
