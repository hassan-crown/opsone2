<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\LicenseLimits;
use App\Access\Permission;
use App\Enums\OrganizationUnitStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-03: add an active branch, within the license's active-branch limit. A new branch is outside every branch or
 * region target, so only a facility-wide grant covers it. The structure screens build on this action.
 */
final class CreateBranch
{
    public function __construct(
        private readonly Authorizer $access,
        private readonly LicenseLimits $limits,
    ) {}

    /**
     * @param  array{name_ar: string, name_en?: string|null, code?: string|null, region_id?: int|null}  $data
     */
    public function handle(User $actor, array $data): Branch
    {
        $branch = new Branch([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'] ?? null,
            'code' => $data['code'] ?? null,
            'region_id' => $data['region_id'] ?? null,
            'status' => OrganizationUnitStatus::Active,
        ]);

        $this->access->authorize($actor, Permission::P03, $branch);

        return DB::transaction(function () use ($branch) {
            $this->limits->reserveBranch();
            $branch->save();

            return $branch;
        });
    }
}
