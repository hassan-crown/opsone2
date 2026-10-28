<?php

namespace App\Models\Inventory;

use App\Access\Contracts\ScopedRecord;
use App\Inventory\CountStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stock count of one location: counted quantities with a reason, approved by a P-49 holder covering the location,
 * which posts the differences as adjustments (never a silent balance edit).
 *
 * @property int $id
 * @property string $number
 * @property int $location_id
 * @property CountStatus $status
 * @property string $reason
 * @property string|null $decision_note
 * @property int|null $created_by
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read StockLocation $location
 * @property-read User|null $creator
 * @property-read User|null $decider
 */
class StockCount extends Model implements ScopedRecord
{
    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'status' => 'draft'];

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class, 'count_id')->orderBy('id');
    }

    public function accessBranchIds(): array
    {
        return $this->location->accessBranchIds();
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
        return $this->created_by === null ? null : (int) $this->created_by;
    }

    protected function casts(): array
    {
        return [
            'status' => CountStatus::class,
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
