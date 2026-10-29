<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * M-12 (FIN-09): a refund of part of one payment's money on one order. The request alone reduces nothing; it reserves
 * its amount from what the payment may still give back. Executed once (T02/T05): a refund journal takes the money off
 * the order and the refund gets its voucher number RF-YYYY-000123. Its amount, payment and order never change.
 *
 * @property int $id
 * @property string|null $number
 * @property int $order_id
 * @property int|null $order_return_id
 * @property int $payment_id
 * @property int $customer_id
 * @property int $amount_minor
 * @property PaymentMethod $method
 * @property string $reason
 * @property RefundStatus $status
 * @property int $attempt
 * @property string|null $reference
 * @property string|null $failure_reason
 * @property string|null $cancel_reason
 * @property int|null $journal_id
 * @property int|null $requested_by
 * @property int|null $executed_by
 * @property CarbonInterface|null $executed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Payment $payment
 * @property-read Customer $customer
 * @property-read User|null $requester
 * @property-read User|null $executor
 */
class Refund extends Model implements AttachmentOwner
{
    use OrderScoped;

    private const FIXED = ['order_id', 'payment_id', 'customer_id', 'amount_minor'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1, 'attempt' => 0];

    protected static function booted(): void
    {
        static::updating(function (self $refund) {
            if (array_intersect(array_keys($refund->getDirty()), self::FIXED) !== [] || ($refund->getOriginal('number') !== null && $refund->isDirty('number'))) {
                throw new LogicException('A refund keeps its payment, order, amount and voucher number.');
            }
        });
        static::deleting(fn () => throw new LogicException('Refunds are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->chaperone();
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor);
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P37;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P43;
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'attempt' => 'integer',
            'executed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
