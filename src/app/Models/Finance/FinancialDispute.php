<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Enums\DisputeOutcome;
use App\Enums\DisputeStatus;
use App\Enums\DisputeSubject;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * M-39 «نزاع مالي» (FIN-13, Q-38): a dispute on an order and one of its money movements, with subject, reason, amount
 * and a responsible person. While open it holds the disputed part of its due from reminders (FIN-11) and blocks the
 * order's full closure; closing a support ticket never resolves it. Never deleted.
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_id
 * @property int|null $refund_id
 * @property int|null $order_due_id
 * @property DisputeSubject $subject
 * @property string $reason
 * @property int $amount_minor
 * @property string|null $amount_note
 * @property int $responsible_id
 * @property DisputeStatus $status
 * @property string|null $external_party
 * @property DisputeOutcome|null $outcome
 * @property string|null $resolution
 * @property int|null $opened_by
 * @property int|null $resolved_by
 * @property CarbonInterface|null $resolved_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Payment|null $payment
 * @property-read Refund|null $refund
 * @property-read OrderDue|null $due
 * @property-read User $responsible
 * @property-read User|null $resolver
 */
class FinancialDispute extends Model implements AttachmentOwner
{
    use OrderScoped;

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Disputes are never deleted.'));
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
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return BelongsTo<OrderDue, $this>
     */
    public function due(): BelongsTo
    {
        return $this->belongsTo(OrderDue::class, 'order_due_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
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
        return Permission::P85;
    }

    protected function casts(): array
    {
        return [
            'subject' => DisputeSubject::class,
            'status' => DisputeStatus::class,
            'outcome' => DisputeOutcome::class,
            'amount_minor' => 'integer',
            'resolved_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
