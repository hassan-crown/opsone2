<?php

namespace App\Models\Procurement;

use App\Models\Attachment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated purchase-order PDF (ADR-0038): one per content fingerprint (the order's lines and dates, the template
 * version, the language and whether prices were shown), kept as a private attachment of the purchase order.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $template_version
 * @property string $fingerprint
 * @property string $locale
 * @property int $attachment_id
 * @property int|null $generated_by
 * @property CarbonInterface $created_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Attachment $attachment
 */
class PurchaseOrderDocument extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    protected function casts(): array
    {
        return ['template_version' => 'integer'];
    }
}
