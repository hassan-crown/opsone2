<?php

namespace App\Enums;

/**
 * Made-to-order (M-04) and ready-goods (M-05) orders are separate records (COM-D01); a shared purchase reference
 * groups the two when they are sold together.
 */
enum OrderKind: string
{
    case Custom = 'custom';
    case Ready = 'ready';

    public function machine(): StateMachine
    {
        return $this === self::Custom ? StateMachine::M04 : StateMachine::M05;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
