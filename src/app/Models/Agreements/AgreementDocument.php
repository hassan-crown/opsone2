<?php

namespace App\Models\Agreements;

use App\Access\Permission;
use App\Enums\ActionStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Models\Attachment;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\Task;
use App\Models\User;
use App\Workflow\Catalogue\ActionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A document that asks for the customer's approval (M-08): a quote built from the order lines, a design file or a
 * contract (from the agreement or an external file). A draft changes freely; once sent its content and hash are
 * fixed and a different content goes out as a replacement (AGR-06). Access follows the order.
 *
 * @property int $id
 * @property int $order_id
 * @property DocumentKind $kind
 * @property string $number
 * @property DocumentStatus $status
 * @property int|null $replaces_id
 * @property array<string, mixed> $content
 * @property string|null $content_hash
 * @property CarbonInterface|null $valid_until
 * @property CarbonInterface|null $sent_at
 * @property int|null $sent_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $cancelled_reason
 * @property int $lock_version
 * @property int|null $created_by
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read self|null $replaces
 */
class AgreementDocument extends Model implements AttachmentOwner
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /** What a sent document keeps for proof (AGR-04, AGR-06). */
    private const FIXED_AFTER_SEND = ['order_id', 'kind', 'number', 'content', 'content_hash', 'replaces_id', 'sent_at', 'sent_by'];

    protected static function booted(): void
    {
        static::updating(function (self $document) {
            if ($document->getRawOriginal('status') !== DocumentStatus::Draft->value
                && array_intersect(array_keys($document->getDirty()), self::FIXED_AFTER_SEND) !== []) {
                throw new LogicException('A sent document keeps its content; send a replacement instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Agreement documents are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isDraft(): bool
    {
        return $this->status === DocumentStatus::Draft;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P16;
    }

    public function attachmentUploadPermission(): Permission
    {
        return $this->kind->preparePermission();
    }

    public function accessBranchIds(): array
    {
        return $this->order->accessBranchIds();
    }

    public function accessDepartmentId(): ?int
    {
        return $this->order->accessDepartmentId();
    }

    /**
     * The order's assignee and, for a design, whoever holds an open «prepare the design» task of the order: the
     * designer uploads with an `assigned` P-33 grant without seeing the rest of the order.
     */
    public function accessAssigneeIds(): array
    {
        $ids = $this->order->accessAssigneeIds();
        if ($this->kind === DocumentKind::Design) {
            $actionIds = OrderAction::query()->where('order_id', $this->order_id)->where('type', ActionType::PrepareDesign->value)
                ->whereIn('status', [ActionStatus::Ready->value, ActionStatus::AwaitingResult->value, ActionStatus::NeedsRework->value])->pluck('id');
            $ids = [...$ids, ...Task::query()->whereIn('order_action_id', $actionIds)->whereNotNull('assignee_id')->pluck('assignee_id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($ids));
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->accessOwnerId();
    }

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'content' => 'array',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
