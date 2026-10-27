<?php

namespace App\Models;

use App\Enums\OwnershipTransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Facility ownership transfer (M-37, proposed in Q-18): requested → accepted | rejected | cancelled | expired.
 *
 * @property int $id
 * @property int $from_membership_id
 * @property int $to_membership_id
 * @property OwnershipTransferStatus $status
 * @property string|null $reason
 * @property Carbon $expires_at
 * @property Carbon|null $decided_at
 * @property-read Membership $from
 * @property-read Membership $to
 */
#[Fillable(['from_membership_id', 'to_membership_id', 'reason', 'expires_at'])]
class OwnershipTransfer extends Model
{
    public const VALID_DAYS = 7;

    protected $attributes = [
        'status' => 'requested',
    ];

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'from_membership_id');
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'to_membership_id');
    }

    protected function casts(): array
    {
        return [
            'status' => OwnershipTransferStatus::class,
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
