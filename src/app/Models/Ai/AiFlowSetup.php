<?php

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A «suggest the setup» request (AI-1). The description and answers are encrypted and hidden from serialization.
 *
 * @property int $id
 * @property string $status
 * @property string|null $base_template
 * @property string $description
 * @property list<array{question: string, answer: string}>|null $answers
 * @property string|null $outcome
 * @property int|null $ai_suggestion_id
 * @property int|null $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property-read AiSuggestion|null $suggestion
 */
#[Hidden(['description', 'answers'])]
class AiFlowSetup extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    /** A running request older than this is shown as failed (its worker died). */
    public const RUNNING_MINUTES = 15;

    public const READY = 'ready';

    public const FAILED = 'failed';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    protected $guarded = [];

    protected $attributes = ['status' => self::PENDING];

    /**
     * @return BelongsTo<AiSuggestion, $this>
     */
    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(AiSuggestion::class, 'ai_suggestion_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING, self::READY], true);
    }

    /**
     * Still waiting for the model (a request whose worker died stops waiting after RUNNING_MINUTES).
     */
    public function waiting(): bool
    {
        return in_array($this->status, [self::PENDING, self::RUNNING], true)
            && $this->updated_at !== null && $this->updated_at->gt(now()->subMinutes(self::RUNNING_MINUTES));
    }

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'answers' => 'encrypted:array',
            'decided_at' => 'datetime',
        ];
    }
}
