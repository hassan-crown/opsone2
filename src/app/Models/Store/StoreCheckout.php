<?php

namespace App\Models\Store;

use App\Models\Customer;
use App\Models\Finance\Payment;
use App\Models\Order;
use App\Store\CheckoutStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One store checkout attempt (WEB-05/06): the ready order awaiting its online payment, the reservation window, the
 * fixed price breakdown and the optional basket link and coupon. `reference` is the only id the browser sees.
 *
 * @property int $id
 * @property string $number
 * @property string $reference
 * @property int $customer_id
 * @property int $order_id
 * @property int|null $link_id
 * @property int|null $coupon_id
 * @property int|null $payment_id
 * @property CheckoutStatus $status
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $vat_minor
 * @property int $total_minor
 * @property array<string, mixed> $pricing
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $paid_at
 * @property string $idempotency_key
 * @property int $provider_failures
 * @property string|null $attention_reason
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Customer $customer
 * @property-read StoreLink|null $link
 * @property-read Coupon|null $coupon
 * @property-read Payment|null $payment
 * @property-read CouponUsage|null $couponUsage
 */
class StoreCheckout extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1, 'discount_minor' => 0, 'provider_failures' => 0];

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

    /**
     * @return BelongsTo<StoreLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(StoreLink::class, 'link_id');
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return HasOne<CouponUsage, $this>
     */
    public function couponUsage(): HasOne
    {
        return $this->hasOne(CouponUsage::class, 'checkout_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'vat_minor' => 'integer',
            'total_minor' => 'integer',
            'pricing' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'provider_failures' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
