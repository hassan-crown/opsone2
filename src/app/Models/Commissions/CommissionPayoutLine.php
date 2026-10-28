<?php

namespace App\Models\Commissions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * What one payout takes from one sale (or from the member's adjustments when the sale is null).
 *
 * @property int $id
 * @property int $payout_id
 * @property int|null $order_sale_id
 * @property int|null $order_id
 * @property int $amount_minor
 */
class CommissionPayoutLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payout lines are fixed when reserved.'));
        static::deleting(fn () => throw new LogicException('Payout lines are fixed when reserved.'));
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
