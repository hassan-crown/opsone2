<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Enums\PaymentMethod;
use App\Enums\ProofStatus;
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
 * M-10: a transfer proof the customer uploaded (FIN-05, P-68). Not money: only its confirmation links it to one
 * payment (an existing one for the same transfer, or a new one). Its claim and file never change; a correction is a
 * new proof that `replaces` it, and the file is never deleted by a refusal.
 *
 * @property int $id
 * @property int $order_id
 * @property int $customer_id
 * @property int $claimed_minor
 * @property PaymentMethod $method
 * @property CarbonInterface $paid_on
 * @property string|null $reference
 * @property string|null $reference_key
 * @property ProofStatus $status
 * @property int|null $replaces_id
 * @property int|null $payment_id
 * @property string|null $correction_reason
 * @property string|null $submitted_mobile
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Customer $customer
 * @property-read Payment|null $payment
 * @property-read User|null $decider
 */
class PaymentProof extends Model implements AttachmentOwner
{
    use OrderScoped;

    private const MUTABLE = ['status', 'payment_id', 'correction_reason', 'decided_by', 'decided_at', 'lock_version', 'updated_at'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::updating(function (self $proof) {
            if (array_diff(array_keys($proof->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('A proof keeps what the customer claimed; a correction is a new proof.');
            }
        });
        static::deleting(fn () => throw new LogicException('Proofs are never deleted.'));
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

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function claimed(): Money
    {
        return Money::of($this->claimed_minor);
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P37;
    }

    public function attachmentUploadPermission(): Permission
    {
        // Uploaded by the customer (P-68) through the portal service; staff never upload a customer's proof.
        return Permission::P68;
    }

    protected function casts(): array
    {
        return [
            'claimed_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => ProofStatus::class,
            'paid_on' => 'date',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
