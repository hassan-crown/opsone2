<?php

namespace App\Models;

use App\Access\Permission;
use App\Enums\TaskStatus;
use App\Files\Contracts\AttachmentOwner;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A unit of work (M-07): from an order action, or a correction linked to an earlier result. Its branch is the order's;
 * the department is its inbox. Executing it (P-24) shows the task's own fields, never the whole order file (WF-08).
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_stage_id
 * @property int|null $order_action_id
 * @property string $kind action | correction
 * @property int|null $corrects_task_id
 * @property Translated $title
 * @property int|null $department_id
 * @property int|null $assignee_id
 * @property int|null $follow_up_user_id
 * @property TaskStatus $status
 * @property CarbonInterface|null $due_at
 * @property string|null $waiting_need
 * @property CarbonInterface|null $waiting_follow_up_on
 * @property string|null $result_note
 * @property CarbonInterface|null $assigned_at
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $completed_at
 * @property int|null $completed_by
 * @property string|null $cancel_reason
 * @property int $lock_version
 * @property-read Order $order
 * @property-read OrderAction|null $action
 * @property-read OrderStage $stage
 * @property-read Department|null $department
 * @property-read User|null $assignee
 */
class Task extends Model implements AttachmentOwner
{
    public const KIND_ACTION = 'action';

    public const KIND_CORRECTION = 'correction';

    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderAction, $this>
     */
    public function action(): BelongsTo
    {
        return $this->belongsTo(OrderAction::class, 'order_action_id');
    }

    /**
     * @return BelongsTo<OrderStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * A copy assigned to someone else, to ask the Gate whether they could hold it (eligibility, M-07-T01/T10).
     */
    public function assignedTo(int $userId): self
    {
        $copy = $this->replicate();
        $copy->assignee_id = $userId;
        $copy->exists = $this->exists;
        $copy->setRelation('order', $this->order);

        return $copy;
    }

    public function attachmentViewPermission(): Permission
    {
        return Permission::P24;
    }

    public function attachmentUploadPermission(): Permission
    {
        return Permission::P24;
    }

    public function accessBranchIds(): array
    {
        return [$this->order->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return $this->department_id;
    }

    public function accessAssigneeIds(): array
    {
        return $this->assignee_id === null ? [] : [(int) $this->assignee_id];
    }

    public function accessOwnerId(): ?int
    {
        return null;
    }

    protected function casts(): array
    {
        return [
            'title' => TranslatableString::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'waiting_follow_up_on' => 'date',
            'assigned_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
