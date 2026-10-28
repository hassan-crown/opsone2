<?php

namespace App\Models\Finance;

use App\Models\Customer;
use App\Models\Order;
use App\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * «سند قبض» (FIN-04): exactly one per confirmed payment, numbered RC-YYYY-000123 without gaps (Q-41). It documents a
 * confirmed collection; it is not a tax invoice. Never changed or deleted — a correction is a reversing entry (6c).
 *
 * @property int $id
 * @property string $number
 * @property int $payment_id
 * @property int $customer_id
 * @property int $order_id
 * @property int $amount_minor
 * @property int|null $issued_by
 * @property CarbonInterface $issued_at
 * @property-read Payment $payment
 * @property-read Order $order
 * @property-read Customer $customer
 */
class Receipt extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A receipt is never changed.'));
        static::deleting(fn () => throw new LogicException('A receipt is never deleted.'));
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor);
    }

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'issued_at' => 'datetime'];
    }
}
