<?php

namespace App\Models\Automation;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One rule against one event (ADR-0042): matched (its actions ran), skipped (and why: conditions, killed, disabled,
 * loop) or failed (an action failed after its retries). The per-rule execution log.
 *
 * @property int $id
 * @property int $automation_event_id
 * @property string $rule_key
 * @property int|null $config_release_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $outcome matched | skipped | failed
 * @property string|null $reason
 * @property array<string, mixed>|null $detail
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read AutomationEvent $event
 */
class AutomationRun extends Model
{
    public const MATCHED = 'matched';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<AutomationEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class, 'automation_event_id');
    }

    /**
     * @return HasMany<AutomationActionLog, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AutomationActionLog::class);
    }

    protected function casts(): array
    {
        return [
            'automation_event_id' => 'integer',
            'config_release_id' => 'integer',
            'subject_id' => 'integer',
            'detail' => 'array',
        ];
    }
}
