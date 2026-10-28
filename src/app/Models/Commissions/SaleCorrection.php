<?php

namespace App\Models\Commissions;

use App\Access\Contracts\ScopedRecord;
use App\Models\Finance\OrderScoped;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A financial decision on a pinned sale (SAL-16, Q-37 a): a new owner (P-98) or a new rate (P-97), with a reason,
 * approved by someone else holding P-97 who is not one of the members it affects.
 *
 * @property int $id
 * @property int $order_sale_id
 * @property int $order_id
 * @property string $kind
 * @property int $from_user_id
 * @property int $to_user_id
 * @property int $from_rate_bp
 * @property int $to_rate_bp
 * @property string $reason
 * @property string $status
 * @property int $requested_by
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property int|null $result_sale_id
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read OrderSale $sale
 */
class SaleCorrection extends Model implements ScopedRecord
{
    use OrderScoped;

    public const OWNER = 'owner';

    public const RATE = 'rate';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderSale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(OrderSale::class, 'order_sale_id');
    }

    /**
     * @return list<int> members whose commission the decision moves: neither may request or decide it
     */
    public function affectedUserIds(): array
    {
        return array_values(array_unique([$this->from_user_id, $this->to_user_id]));
    }

    protected function casts(): array
    {
        return [
            'from_rate_bp' => 'integer',
            'to_rate_bp' => 'integer',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
