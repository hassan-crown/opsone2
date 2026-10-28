<?php

namespace App\Models\Inventory;

use App\Access\Contracts\ScopedRecord;
use App\Models\Branch;
use App\Models\User;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A simple stock location (FUL-01): code, bilingual name, optional branch, status and person in charge. A location
 * without a branch is facility-wide, so only facility-scope grants cover it; a branch grant covers its branch's
 * locations (ADR-0017).
 *
 * @property int $id
 * @property string $code
 * @property Translated $name
 * @property int|null $branch_id
 * @property string $status
 * @property int|null $manager_id
 * @property bool $is_default
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Branch|null $branch
 * @property-read User|null $manager
 */
class StockLocation extends Model implements ScopedRecord
{
    public const STATUSES = ['active', 'disabled'];

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'status' => 'active', 'is_default' => false];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function accessBranchIds(): array
    {
        return $this->branch_id === null ? [] : [(int) $this->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->manager_id === null ? [] : [(int) $this->manager_id];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'is_default' => 'boolean',
            'lock_version' => 'integer',
        ];
    }
}
