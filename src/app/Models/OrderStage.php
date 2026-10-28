<?php

namespace App\Models;

use App\Access\Contracts\ScopedRecord;
use App\Enums\StageStatus;
use App\Support\Translatable\TranslatableString;
use App\Support\Translatable\Translated;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stage of one order, copied from its pinned flow version (M-06). P-25 is checked on the stage: its follow-up
 * department and person put it in a supervisor's department or assigned scope (Q-30 a).
 *
 * @property int $id
 * @property int $order_id
 * @property int $position
 * @property string $key
 * @property Translated $name
 * @property Translated $public_name
 * @property bool $show_to_customer
 * @property string $follow_up_type
 * @property int|null $follow_up_user_id
 * @property int|null $follow_up_department_id
 * @property int|null $target_days
 * @property StageStatus $status
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property int|null $completed_by
 * @property-read Order $order
 */
class OrderStage extends Model implements ScopedRecord
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
     * @return HasMany<OrderAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(OrderAction::class)->orderBy('position');
    }

    public function accessBranchIds(): array
    {
        return [$this->order->branch_id];
    }

    public function accessDepartmentId(): ?int
    {
        return $this->follow_up_department_id;
    }

    public function accessAssigneeIds(): array
    {
        return array_values(array_unique(array_filter([$this->follow_up_user_id, $this->order->assignee_id])));
    }

    public function accessOwnerId(): ?int
    {
        return $this->order->created_by;
    }

    protected function casts(): array
    {
        return [
            'name' => TranslatableString::class,
            'public_name' => TranslatableString::class,
            'show_to_customer' => 'boolean',
            'status' => StageStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'target_days' => 'integer',
        ];
    }
}
