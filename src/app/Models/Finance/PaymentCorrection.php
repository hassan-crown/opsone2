<?php

namespace App\Models\Finance;

use App\Enums\CorrectionKind;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * «سند تصحيح» (FIN-08, M-11-T07): the documented correction of one confirmed payment — the reversal journals that took
 * its effects back and, for an amendment, the payment re-entered with the right figures. The original payment and its
 * receipt stay as they were. Written once.
 *
 * @property int $id
 * @property string $number
 * @property int $payment_id
 * @property int $order_id
 * @property CorrectionKind $kind
 * @property string $reason
 * @property int|null $replacement_payment_id
 * @property list<int> $reversed_journals
 * @property array<string, mixed> $before
 * @property array<string, mixed>|null $after
 * @property int|null $corrected_by
 * @property CarbonInterface $created_at
 * @property-read Payment $payment
 * @property-read Payment|null $replacement
 * @property-read Order $order
 * @property-read User|null $corrector
 */
class PaymentCorrection extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A correction voucher is never changed.'));
        static::deleting(fn () => throw new LogicException('A correction voucher is never deleted.'));
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'replacement_payment_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    protected function casts(): array
    {
        return [
            'kind' => CorrectionKind::class,
            'reversed_journals' => 'array',
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
