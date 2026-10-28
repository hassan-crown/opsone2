<?php

namespace App\Models\Agreements;

use App\Enums\DiscountDecisionStatus;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to exceed the preparer's discount limit (M-26, AGR-02), bound to the exact content (hash) of a quote or
 * change. Approved by someone else with P-31 in scope and a higher approval limit; any later change to the priced
 * content supersedes it (T04) and needs a new request.
 *
 * @property int $id
 * @property int $order_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $content_hash
 * @property DiscountDecisionStatus $status
 * @property int $requested_by
 * @property int $base_minor
 * @property int $discount_minor
 * @property int $limit_bp
 * @property string $reason
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property string|null $decision_reason
 * @property CarbonInterface|null $superseded_at
 * @property int $lock_version
 * @property CarbonInterface $created_at
 * @property-read Order $order
 * @property-read User $requester
 * @property-read User|null $decider
 */
class DiscountDecision extends Model
{
    public const SUBJECT_DOCUMENT = 'document';

    public const SUBJECT_CHANGE = 'change';

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
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function casts(): array
    {
        return [
            'status' => DiscountDecisionStatus::class,
            'base_minor' => 'integer',
            'discount_minor' => 'integer',
            'limit_bp' => 'integer',
            'decided_at' => 'datetime',
            'superseded_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
