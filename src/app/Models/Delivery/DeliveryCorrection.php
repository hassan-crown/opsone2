<?php

namespace App\Models\Delivery;

use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * M-14-T03: a correction of a confirmed delivery (ADR-0034). Append-only: the note keeps what was recorded, the
 * correction takes part of it back and the stock goes to `destination` (general stock or the customer's reservation).
 *
 * @property int $id
 * @property int $delivery_note_id
 * @property int $order_id
 * @property string|null $destination
 * @property string $reason
 * @property CarbonInterface|null $first_delivery_on
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read DeliveryNote $note
 * @property-read User|null $creator
 */
class DeliveryCorrection extends Model
{
    public const TO_STOCK = 'stock';

    public const TO_RESERVATION = 'reservation';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Delivery corrections are append-only.'));
        static::deleting(fn () => throw new LogicException('Delivery corrections are append-only.'));
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<DeliveryCorrectionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryCorrectionLine::class)->orderBy('id');
    }

    protected function casts(): array
    {
        return ['first_delivery_on' => 'date'];
    }
}
