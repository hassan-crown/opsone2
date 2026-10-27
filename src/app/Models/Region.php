<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\OrganizationUnitStatus;
use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name_ar
 * @property string|null $name_en
 * @property OrganizationUnitStatus $status
 */
#[Fillable(['name_ar', 'name_en', 'status'])]
class Region extends Model implements ScopedRecord
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function accessBranchIds(): array
    {
        return array_values(array_map(intval(...), $this->branches()->pluck('id')->all()));
    }

    public function accessDepartmentId(): ?int
    {
        return null;
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
