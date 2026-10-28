<?php

namespace App\Workflow;

use App\Access\Exceptions\InvalidTransition;
use App\Enums\StateMachine;
use BackedEnum;

/**
 * Guard shared by the P5 machines: a code the machine does not define, or one not allowed from the current state,
 * is refused before any write (no partial effect).
 */
final class Transition
{
    /**
     * @template TState of BackedEnum
     *
     * @param  array<string, array{from: list<TState|null>, to: TState|null}>  $table
     * @param  TState|null  $current
     * @return TState|null the defined target (null when the action chooses it)
     */
    public static function check(StateMachine $machine, array $table, ?BackedEnum $current, string $code): ?BackedEnum
    {
        $definition = $table[$code] ?? throw new InvalidTransition($machine, $code, 'undefined');

        if (! in_array($current, $definition['from'], true)) {
            throw new InvalidTransition($machine, $code, 'not_from_'.($current === null ? 'none' : $current->value));
        }

        return $definition['to'];
    }

    public static function refuse(StateMachine $machine, string $code, string $condition): never
    {
        throw new InvalidTransition($machine, $code, $condition);
    }
}
