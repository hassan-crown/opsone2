<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * M-11: one real money movement from the customer (FIN-03..07). Its amount, method, date and reference never change
 * after it is written; its effects on orders and dues are journal entries. A trusted reference (`reference_key`) is
 * unique, so one transfer is one payment whichever channel reports it (INT-04).
 *
 * @property int $id
 * @property int $customer_id
 * @property int $order_id
 * @property int $amount_minor
 * @property PaymentMethod $method
 * @property CarbonInterface $paid_on
 * @property string|null $reference
 * @property string|null $reference_key
 * @property string $origin
 * @property PaymentStatus $status
 * @property string|null $gateway
 * @property string|null $note
 * @property int|null $recorded_by
 * @property int|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Customer $customer
 * @property-read Receipt|null $receipt
 * @property-read User|null $recorder
 */
class Payment extends Model implements AttachmentOwner
{
    use OrderScoped;

    public const ORIGIN_STAFF = 'staff';

    public const ORIGIN_PROOF = 'customer_proof';

    public const ORIGIN_GATEWAY = 'gateway';

    /** What may change after writing: the M-11 state and who confirmed it. */
    private const MUTABLE = ['status', 'confirmed_by', 'confirmed_at', 'lock_version', 'updated_at'];

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            if (array_diff(array_keys($payment->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('A payment keeps its amount, method, date and reference; correct it with a reversing entry.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payments are never deleted.'));
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
     * @return HasOne<Receipt, $this>
     */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
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
        return Permission::P39;
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'paid_on' => 'date',
            'confirmed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
