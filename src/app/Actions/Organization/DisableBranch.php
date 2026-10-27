<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-03: disable a branch within the actor's scope. History is kept; the last active branch cannot be disabled
 * (the facility always has a place to work). Open work handover joins when orders exist (P3+).
 */
final class DisableBranch
{
    public function __construct(private readonly Authorizer $access) {}

    public function handle(User $actor, Branch $branch): Branch
    {
        $this->access->authorize($actor, Permission::P03, $branch);

        return DB::transaction(function () use ($branch) {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if ($locked->status !== OrganizationUnitStatus::Active) {
                throw ValidationException::withMessages(['branch' => __('org.branches.error_not_active')]);
            }
            if (Branch::query()->where('status', OrganizationUnitStatus::Active->value)->whereKeyNot($locked->id)->doesntExist()) {
                throw ValidationException::withMessages(['branch' => __('org.branches.error_last_active')]);
            }

            $locked->forceFill(['status' => OrganizationUnitStatus::Disabled])->save();

            return $locked;
        });
    }
}
