<?php

namespace App\Models\Agreements;

use App\Enums\AgreementStatus;
use App\Models\Order;
use App\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of the order's agreement (AGR-01, AGR-07): the content the customer approved with its totals. Written
 * once; a change creates the next version and only marks this one superseded. Read through App\Agreements\OrderAgreement.
 *
 * @property int $id
 * @property int $order_id
 * @property int $version
 * @property AgreementStatus $status
 * @property int|null $active_key
 * @property string $source_type
 * @property int $source_id
 * @property int $customer_decision_id
 * @property array<string, mixed> $content
 * @property int $net_minor
 * @property int $vat_minor
 * @property int $total_minor
 * @property int $discount_minor
 * @property int $products_minor
 * @property int|null $previous_total_minor
 * @property int $difference_minor
 * @property int $settlement_needed_minor
 * @property CarbonInterface $approved_at
 * @property CarbonInterface|null $superseded_at
 * @property-read Order $order
 * @property-read CustomerDecision $customerDecision
 */
class Agreement extends Model
{
    /** Columns that change after the version is written: being superseded, nothing else. */
    private const MUTABLE = ['status', 'active_key', 'superseded_at', 'updated_at'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $agreement) {
            if (array_diff(array_keys($agreement->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('An approved agreement is immutable; apply a change to create a new version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Agreements are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<CustomerDecision, $this>
     */
    public function customerDecision(): BelongsTo
    {
        return $this->belongsTo(CustomerDecision::class);
    }

    public function total(): Money
    {
        return Money::of($this->total_minor);
    }

    protected function casts(): array
    {
        return [
            'status' => AgreementStatus::class,
            'content' => 'array',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'total_minor' => 'integer',
            'discount_minor' => 'integer',
            'products_minor' => 'integer',
            'previous_total_minor' => 'integer',
            'difference_minor' => 'integer',
            'settlement_needed_minor' => 'integer',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
