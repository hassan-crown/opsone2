<?php

namespace App\Models\Procurement;

use App\Access\Permission;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * One physical arrival from the supplier (FUL-06, P-47): append-only. Its delivery proofs are private attachments
 * that follow the purchase order's scope.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $location_id
 * @property CarbonInterface $received_on
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $received_by
 * @property CarbonInterface $created_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read User|null $receiver
 */
class PurchaseReceipt extends Model implements AttachmentOwner
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receipts are append-only.'));
        static::deleting(fn () => throw new LogicException('Receipts are append-only.'));
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<PurchaseReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->chaperone()->orderBy('id');
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P47;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P47;
    }

    public function accessBranchIds(): array
    {
        return $this->purchaseOrder->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return null;
    }

    public function accessAssigneeIds(): array
    {
        return $this->purchaseOrder->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->purchaseOrder->accessOwnerId();
    }

    protected function casts(): array
    {
        return ['received_on' => 'date', 'location_id' => 'integer'];
    }
}
