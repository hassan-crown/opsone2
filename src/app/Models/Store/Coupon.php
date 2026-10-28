<?php

namespace App\Models\Store;

use App\Store\CouponStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A ready-goods coupon (SAL-06): percent (basis points) or fixed amount (halalas) on the eligible products before
 * VAT, with dates, variant scope, minimum, total and per-customer caps. A confirmed use keeps its own discount; editing
 * the coupon never re-prices it.
 *
 * @property int $id
 * @property string $code
 * @property array{ar?: string, en?: string} $title
 * @property string $kind
 * @property int $value
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $ends_at
 * @property list<int>|null $variant_ids
 * @property int|null $min_subtotal_minor
 * @property int|null $max_uses
 * @property int|null $max_uses_per_customer
 * @property int $uses_count
 * @property CarbonInterface|null $paused_at
 * @property int|null $created_by
 * @property int $lock_version
 */
class Coupon extends Model
{
    public const PERCENT = 'percent';

    public const AMOUNT = 'amount';

    protected $guarded = ['id'];

    protected $attributes = ['uses_count' => 0, 'lock_version' => 1];

    /**
     * Upper case, 3..32 letters, digits or dashes (SAL-06).
     */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function status(?CarbonInterface $at = null): CouponStatus
    {
        $at ??= now();

        return match (true) {
            $this->ends_at !== null && $this->ends_at->lessThanOrEqualTo($at) => CouponStatus::Ended,
            $this->paused_at !== null => CouponStatus::Paused,
            $this->starts_at->greaterThan($at) => CouponStatus::Scheduled,
            default => CouponStatus::Active,
        };
    }

    public function titleIn(string $locale): string
    {
        $title = $this->title;

        return (string) ($title[$locale] ?? $title['ar'] ?? $this->code);
    }

    public function appliesTo(int $variantId): bool
    {
        return $this->variant_ids === null || in_array($variantId, $this->variant_ids, true);
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'value' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'variant_ids' => 'array',
            'min_subtotal_minor' => 'integer',
            'max_uses' => 'integer',
            'max_uses_per_customer' => 'integer',
            'uses_count' => 'integer',
            'paused_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
