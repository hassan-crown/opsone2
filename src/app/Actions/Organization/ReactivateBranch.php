<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P-03: reactivate a disabled branch. It becomes an active branch again, so the license branch limit applies.
 */
final class ReactivateBranch
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly LicenseLimits $limits,
    ) {}

    public function handle(User $actor, Branch $branch): Branch
    {
        $this->access->authorize($actor, Permission::P03, $branch);

        return DB::transaction(function () use ($branch) {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if ($locked->status !== OrganizationUnitStatus::Disabled) {
                throw ValidationException::withMessages(['branch' => __('org.branches.error_not_disabled')]);
            }
            $this->limits->reserveBranch();

            $locked->forceFill(['status' => OrganizationUnitStatus::Active])->save();

            return $locked;
        });
    }
}
