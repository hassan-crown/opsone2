<?php

namespace App\Models\Finance;

use App\Access\Permission;
use App\Enums\ExternalDocumentEvent;
use App\Enums\ExternalDocumentKind;
use App\Enums\ExternalDocumentStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * M-40 «مستند محاسبة خارجي» (FIN-10, Q-39, BR-20): an invoice or note issued by the external accounting system and
 * linked here to the order and the event it covers, with its source, number, date, amount and file. The number is
 * unique per source. Linking or accepting it never claims compliance and OpsOne never issues a tax invoice. A
 * correction keeps every earlier file. Never deleted.
 *
 * @property int $id
 * @property int $order_id
 * @property ExternalDocumentKind $kind
 * @property ExternalDocumentEvent $event
 * @property int|null $payment_id
 * @property int|null $refund_id
 * @property string|null $source
 * @property string|null $source_key
 * @property string|null $number
 * @property string|null $number_key
 * @property CarbonInterface|null $issued_on
 * @property int|null $amount_minor
 * @property string $currency
 * @property string|null $note
 * @property ExternalDocumentStatus $status
 * @property string|null $correction_reason
 * @property int|null $requested_by
 * @property int|null $attached_by
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read Payment|null $payment
 * @property-read Refund|null $refund
 * @property-read User|null $reviewer
 */
class ExternalDocument extends Model implements AttachmentOwner
{
    use OrderScoped;

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1, 'currency' => 'SAR'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('External documents are never deleted.'));
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
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->chaperone();
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P44;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P44;
    }

    protected function casts(): array
    {
        return [
            'kind' => ExternalDocumentKind::class,
            'event' => ExternalDocumentEvent::class,
            'status' => ExternalDocumentStatus::class,
            'issued_on' => 'date',
            'amount_minor' => 'integer',
            'reviewed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
