<?php

namespace App\Models\Store;

use App\Store\CouponUsageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * M-32: one coupon use bound to one checkout attempt — reserved while the payment is pending, consumed with the
 * confirmed purchase, released after a settled failure (never twice, never back after a refund).
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $checkout_id
 * @property int $customer_id
 * @property int $order_id
 * @property CouponUsageStatus $status
 * @property int $discount_minor
 * @property-read Coupon $coupon
 */
class CouponUsage extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    protected function casts(): array
    {
        return ['status' => CouponUsageStatus::class, 'discount_minor' => 'integer'];
    }
}
