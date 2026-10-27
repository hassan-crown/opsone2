<?php

namespace App\Actions\Organization;

use App\Access\Authorizer;
use App\Access\Permission;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * P-03: edit a branch within the actor's grant scope. Moving branches between regions, disabling with open work
 * and plan limits come with the structure screens (P2 wave 2).
 */
final class UpdateBranch
{
    private const FIELDS = ['name_ar', 'name_en', 'code'];

    public function __construct(private readonly Authorizer $access) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Branch $branch, array $data): Branch
    {
        $this->access->authorize($actor, Permission::P03, $branch);

        return DB::transaction(function () use ($branch, $data) {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            $locked->fill(array_intersect_key($data, array_flip(self::FIELDS)))->save();

            return $locked;
        });
    }
}
