<?php

namespace App\Models\Commissions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One signed difference of a sale's accrual (SAL-13, INT-06): never updated or deleted, the sum is the accrual.
 *
 * @property int $id
 * @property int $order_sale_id
 * @property int $order_id
 * @property int $user_id
 * @property string $kind
 * @property int $amount_minor
 * @property int|null $eligible_minor
 * @property int|null $collected_minor
 * @property int|null $agreed_minor
 * @property int $rate_bp
 * @property int $accrued_minor
 * @property string $source
 * @property string|null $reason
 * @property int|null $actor_id
 * @property CarbonInterface $created_at
 * @property-read OrderSale $sale
 */
class CommissionEntry extends Model
{
    public const ACCRUAL = 'accrual';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Commission entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Commission entries are append-only.'));
    }

    /**
     * @return BelongsTo<OrderSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(OrderSale::class, 'order_sale_id');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'eligible_minor' => 'integer',
            'collected_minor' => 'integer',
            'agreed_minor' => 'integer',
            'rate_bp' => 'integer',
            'accrued_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
