<?php

namespace App\Enums;

/**
 * M-39 «النزاع المالي» (FIN-13, Q-38): open → awaiting an external party → resolved with proof of the treatment.
 * Changing the state never moves money: a refund or a correction runs through its own action.
 */
enum DisputeStatus: string
{
    case Open = 'open';
    case AwaitingExternal = 'awaiting_external';
    case Resolved = 'resolved';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Open], 'to' => self::AwaitingExternal],
            'T02' => ['from' => [self::AwaitingExternal], 'to' => self::Open],
            'T03' => ['from' => [self::Open, self::AwaitingExternal], 'to' => self::Resolved],
        ];
    }

    public function isOpen(): bool
    {
        return $this !== self::Resolved;
    }
}
