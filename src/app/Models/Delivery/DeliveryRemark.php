<?php

namespace App\Models\Delivery;

use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A blocking remark on a confirmed delivery (M-14-T04): the delivery stands, the order cannot close until it is resolved.
 *
 * @property int $id
 * @property int $delivery_note_id
 * @property int $order_id
 * @property string $description
 * @property string $status
 * @property int|null $follow_up_user_id
 * @property int|null $created_by
 * @property string|null $resolution
 * @property int|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property int $lock_version
 */
class DeliveryRemark extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $guarded = ['id', 'lock_version'];

    protected $attributes = ['lock_version' => 1, 'status' => self::OPEN];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** @return BelongsTo<DeliveryNote, $this> */
    public function note(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class, 'delivery_note_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
