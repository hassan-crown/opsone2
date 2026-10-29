<?php

namespace App\Models\Delivery;

use App\Access\Permission;
use App\Enums\DeliveryStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A delivery note (M-14, P-52): what was actually handed over, to whom, when, with proof. It is a ScopedRecord on its
 * order, so every grant is checked against the order's branch / assignee / department; proofs are private attachments.
 *
 * @property int $id
 * @property string|null $number
 * @property int $order_id
 * @property DeliveryStatus $status
 * @property CarbonInterface|null $delivered_on
 * @property string|null $receiver_name
 * @property string|null $note
 * @property int|null $confirmed_by
 * @property CarbonInterface|null $confirmed_at
 * @property string|null $cancel_reason
 * @property int $lock_version
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read Order $order
 */
class DeliveryNote extends Model implements AttachmentOwner
{
    protected $guarded = ['id', 'number', 'lock_version'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<DeliveryNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
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
        return Permission::P52;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P52;
    }

    public function accessBranchIds(): array
    {
        return $this->order->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return $this->order->accessDepartmentId();
    }

    public function accessAssigneeIds(): array
    {
        return $this->order->accessAssigneeIds();
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->accessOwnerId();
    }

    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'delivered_on' => 'date',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
