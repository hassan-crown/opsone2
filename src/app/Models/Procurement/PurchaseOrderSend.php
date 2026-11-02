<?php

namespace App\Models\Procurement;

use App\Models\OutboxMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emailed copy of a purchase order to its supplier (ADR-0038). The outbox row delivers it (attempts, backoff,
 * last error); its final status is mirrored here because delivered outbox rows are pruned. A retry is a new send
 * pointing at the one it repeats.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $purchase_order_document_id
 * @property int|null $outbox_message_id
 * @property string $recipient
 * @property string $subject
 * @property string $body
 * @property string $locale
 * @property string $status
 * @property CarbonInterface|null $sent_at
 * @property int|null $retry_of
 * @property int|null $sent_by
 * @property CarbonInterface $created_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read PurchaseOrderDocument $document
 * @property-read OutboxMessage|null $outbox
 * @property-read User|null $sender
 */
class PurchaseOrderSend extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $attributes = ['status' => self::QUEUED];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDocument::class, 'purchase_order_document_id');
    }

    /**
     * @return BelongsTo<OutboxMessage, $this>
     */
    public function outbox(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
