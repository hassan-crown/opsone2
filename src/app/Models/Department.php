<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\OrganizationUnitStatus;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A department may serve one or several branches.
 *
 * @property int $id
 * @property string $name_ar
 * @property string|null $name_en
 * @property OrganizationUnitStatus $status
 */
#[Fillable(['name_ar', 'name_en', 'status'])]
class Department extends Model implements ScopedRecord
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function accessBranchIds(): array
    {
        return array_values(array_map(intval(...), $this->branches()->pluck('branches.id')->all()));
    }

    public function accessDepartmentId(): ?int
    {
        return $this->id;
    }

    public function accessAssigneeIds(): array
    {
        return [];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return ['status' => OrganizationUnitStatus::class];
    }
}
