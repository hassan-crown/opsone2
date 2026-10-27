<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\OrganizationUnitStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $region_id
 * @property string|null $code
 * @property string $name_ar
 * @property string|null $name_en
 * @property OrganizationUnitStatus $status
 * @property-read Region|null $region
 */
#[Fillable(['region_id', 'code', 'name_ar', 'name_en', 'status'])]
class Branch extends Model implements ScopedRecord
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class);
    }

    public function accessBranchIds(): array
    {
        return [$this->id];
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
