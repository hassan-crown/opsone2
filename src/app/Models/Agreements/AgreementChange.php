<?php

namespace App\Models\Agreements;

use App\Enums\ChangeStatus;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A proposed change to the approved agreement (M-09, AGR-07): the new content, the money difference, the work it
 * holds and the extra days. The previous agreement stays in force until the change is approved and applied once.
 *
 * @property int $id
 * @property int $order_id
 * @property string $number
 * @property int $base_agreement_id
 * @property ChangeStatus $status
 * @property int|null $open_key
 * @property string $description
 * @property array<string, mixed> $content
 * @property string|null $content_hash
 * @property list<string>|null $affected
 * @property int $delay_days
 * @property int|null $difference_minor
 * @property list<array<string, mixed>>|null $presentations
 * @property string|null $revision_reason
 * @property CarbonInterface|null $presented_at
 * @property int|null $presented_by
 * @property int|null $applied_agreement_id
 * @property string|null $cancelled_reason
 * @property int $lock_version
 * @property int|null $created_by
 * @property-read Order $order
 * @property-read Agreement $baseAgreement
 */
class AgreementChange extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1];

    protected static function booted(): void
    {
        static::updating(function (self $change) {
            $was = $change->getRawOriginal('status');
            if (in_array($was, [ChangeStatus::Applied->value, ChangeStatus::Cancelled->value], true)) {
                throw new LogicException('A settled agreement change is kept as it is.');
            }
            // What the customer is looking at stays fixed while it waits for the decision.
            if ($was === ChangeStatus::AwaitingApproval->value && array_intersect(array_keys($change->getDirty()), ['content', 'content_hash', 'affected', 'delay_days']) !== []) {
                throw new LogicException('A presented change keeps its content until a revision is requested.');
            }
        });
        static::deleting(fn () => throw new LogicException('Agreement changes are never deleted.'));
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function baseAgreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'base_agreement_id');
    }

    protected function casts(): array
    {
        return [
            'status' => ChangeStatus::class,
            'content' => 'array',
            'affected' => 'array',
            'presentations' => 'array',
            'delay_days' => 'integer',
            'difference_minor' => 'integer',
            'presented_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
