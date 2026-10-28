<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom order's pinned flow version and its single active main stage (WF-06, WF-10), plus the pause record.
 *
 * @property int $id
 * @property int $order_id
 * @property int $flow_version_id
 * @property int|null $active_stage_id
 * @property string|null $pause_reason
 * @property int|null $pause_follow_up_id
 * @property CarbonInterface|null $pause_review_on
 * @property-read FlowVersion $flowVersion
 * @property-read OrderStage|null $activeStage
 */
class OrderWorkflow extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<FlowVersion, $this>
     */
    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    /**
     * @return BelongsTo<OrderStage, $this>
     */
    public function activeStage(): BelongsTo
    {
        return $this->belongsTo(OrderStage::class, 'active_stage_id');
    }

    protected function casts(): array
    {
        return ['pause_review_on' => 'date'];
    }
}
