<?php

namespace App\Access;

use App\Enums\StateMachine;
use App\Models\StateTransition;
use App\Models\User;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * Appends one row to `state_transitions`. Call inside the transaction that changes the state.
 * Creation transitions use the implicit code `T00` (Q-21).
 */
final class TransitionRecorder
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCE_SEEDER = 'seeder';

    public const SOURCE_PROVISIONING = 'provisioning';

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        StateMachine $machine,
        string $transition,
        Model $subject,
        BackedEnum|string|null $from,
        BackedEnum|string $to,
        ?User $actor,
        string $source,
        ?string $reason = null,
        array $context = [],
    ): StateTransition {
        return StateTransition::query()->create([
            'machine' => $machine,
            'transition' => $transition,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'from_state' => $from instanceof BackedEnum ? (string) $from->value : $from,
            'to_state' => $to instanceof BackedEnum ? (string) $to->value : $to,
            'actor_user_id' => $actor?->id,
            'source' => $source,
            'reason' => $reason,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }
}
