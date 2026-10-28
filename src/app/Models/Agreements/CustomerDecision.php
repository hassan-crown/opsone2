<?php

namespace App\Models\Agreements;

use App\Access\Permission;
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
 * A customer's decision on the exact content of a document or change (AGR-04/05/09): from the customer's own account
 * (P-67, the portal in P8) or recorded by staff from an external approval with channel and evidence (P-34, Q-40 a —
 * electronic approval replaces a signature). Append-only; the evidence files are its attachments.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $order_id
 * @property string $content_hash
 * @property string $decision
 * @property string $method
 * @property string|null $channel
 * @property CarbonInterface $decided_at
 * @property int|null $recorded_by
 * @property int $customer_id
 * @property string|null $customer_mobile
 * @property string|null $note
 * @property string|null $approval_key
 * @property-read Order $order
 * @property-read User|null $recorder
 */
class CustomerDecision extends Model implements AttachmentOwner
{
    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const METHOD_ACCOUNT = 'customer_account';

    public const METHOD_EXTERNAL = 'external';

    public const CHANNELS = ['phone', 'message', 'paper', 'in_person', 'email', 'other'];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A customer decision is never changed.'));
        static::deleting(fn () => throw new LogicException('A customer decision is never deleted.'));
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

    public function attachmentViewPermission(): Permission
    {
        return Permission::P16;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P34;
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
        return ['decided_at' => 'datetime'];
    }
}
