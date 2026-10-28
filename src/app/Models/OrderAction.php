<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\ActionStatus;
use App\Enums\TaskStatus;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use App\Workflow\Catalogue\ActionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An action of one order and its result (M-27). The type, inputs and dependencies are copied from the pinned version.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_stage_id
 * @property int $position
 * @property string $key
 * @property ActionType $type
 * @property Translated $name
 * @property string $requirement
 * @property string $applies_when
 * @property array<string, mixed>|null $inputs
 * @property list<string>|null $depends_on
 * @property string $assign_type
 * @property int|null $assign_id
 * @property ActionStatus $status
 * @property int|null $held_by_action_id
 * @property array<string, mixed>|null $source_reference
 * @property CarbonInterface|null $verified_at
 * @property int|null $allowed_by
 * @property string|null $allowed_reason
 * @property int $rework_count
 * @property-read Order $order
 * @property-read OrderStage $stage
 */
class OrderAction extends Model implements ScopedRecord
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'order_stage_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('id');
    }

    public function isRequired(): bool
    {
        return $this->requirement === 'required';
    }

    /**
     * Whether the action's need condition holds for its order now (WF-05).
     */
    public function appliesTo(Order $order): bool
    {
        return match ($this->applies_when) {
            'needs_measurement' => $order->needs_measurement,
            'needs_design' => $order->needs_design,
            default => true,
        };
    }

    public function accessBranchIds(): array
    {
        return [$this->order->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return $this->assign_type === 'department' ? $this->assign_id : null;
    }

    public function accessAssigneeIds(): array
    {
        $ids = $this->tasks()->whereIn('status', TaskStatus::open())->whereNotNull('assignee_id')->pluck('assignee_id')->all();
        $ids[] = $this->order->assignee_id;

        return array_values(array_unique(array_map(intval(...), array_filter($ids))));
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->created_by;
    }

    protected function casts(): array
    {
        return [
            'type' => ActionType::class,
            'name' => TranslatableString::class,
            'inputs' => 'array',
            'depends_on' => 'array',
            'status' => ActionStatus::class,
            'source_reference' => 'array',
            'verified_at' => 'datetime',
            'rework_count' => 'integer',
        ];
    }
}
