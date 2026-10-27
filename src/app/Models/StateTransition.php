<?php

namespace App\Models;

use App\Enums\StateMachine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only log of every state transition. Rows are never updated or deleted; a correction is a new transition.
 *
 * @property int $id
 * @property StateMachine $machine
 * @property string $transition
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $from_state
 * @property string $to_state
 * @property int|null $actor_user_id
 * @property string $source
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
#[Fillable(['machine', 'transition', 'subject_type', 'subject_id', 'from_state', 'to_state', 'actor_user_id', 'source', 'reason', 'context', 'occurred_at'])]
class StateTransition extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('State transitions are append-only.'));
        static::deleting(fn () => throw new LogicException('State transitions are append-only.'));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'machine' => StateMachine::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
