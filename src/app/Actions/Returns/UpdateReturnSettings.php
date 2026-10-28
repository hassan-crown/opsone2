<?php

namespace App\Actions\Returns;

use App\Access\Access;
use App\Access\Permission;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Models\Returns\ReturnSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ready-goods return window (P-02, SRS «إعدادات المبيعات»): days from the actual delivery, default 14. A change
 * applies to requests raised afterwards — each return keeps the window in force when it was requested.
 */
final class UpdateReturnSettings
{
    public function handle(User $actor, int $windowDays): ReturnSettings
    {
        Access::authorize($actor, Permission::P02);

        return DB::transaction(function () use ($actor, $windowDays) {
            $settings = ReturnSettings::query()->lockForUpdate()->orderBy('id')->first() ?? new ReturnSettings;
            $before = $settings->exists ? ['window_days' => $settings->window_days] : null;
            $settings->forceFill(['window_days' => $windowDays, 'updated_by' => $actor->id])->save();
            Audit::record(AuditAction::ReturnSettingsUpdated, $settings, $actor, before: $before ?? [], after: ['window_days' => $windowDays]);

            return $settings;
        });
    }
}
