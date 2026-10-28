<?php

namespace App\Models\Inventory;

use App\Access\Contracts\ScopedRecord;
use App\Inventory\TransferStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * M-25: a transfer between two locations — linked exit and arrival; while in transit the quantity is neither
 * available at the source nor at the destination.
 *
 * @property int $id
 * @property string $number
 * @property int $from_location_id
 * @property int $to_location_id
 * @property TransferStatus $status
 * @property string|null $note
 * @property int|null $created_by
 * @property CarbonInterface|null $dispatched_at
 * @property CarbonInterface|null $received_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read StockLocation $from
 * @property-read StockLocation $to
 */
class StockTransfer extends Model implements ScopedRecord
{
    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'status' => 'draft'];

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<StockLocation, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class, 'transfer_id')->orderBy('id');
    }

    /**
     * Source and destination must both be covered; a facility-wide location needs a facility grant.
     */
    public function accessBranchIds(): array
    {
        if ($this->from->branch_id === null || $this->to->branch_id === null) {
            return [];
        }

        return array_values(array_unique([(int) $this->from->branch_id, (int) $this->to->branch_id]));
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
        return [
            'status' => TransferStatus::class,
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
