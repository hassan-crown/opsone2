<?php

namespace App\Enums;

/**
 * M-16 «الموعد». «مؤكد مع مقترح تغيير» is `confirmed` plus a separate `pending_change` (composite state rule).
 * T07 (the customer picks another time, P-70) belongs to the customer portal and is not served by the staff app.
 */
enum AppointmentStatus: string
{
    case Proposed = 'proposed';
    case AwaitingConfirmation = 'awaiting_confirmation';
    case Confirmed = 'confirmed';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Proposed], 'to' => self::AwaitingConfirmation],
            'T02' => ['from' => [self::Proposed, self::AwaitingConfirmation], 'to' => self::Confirmed],
            'T03' => ['from' => [self::Confirmed], 'to' => self::Confirmed],
            'T04' => ['from' => [self::Confirmed], 'to' => self::Confirmed],
            'T05' => ['from' => [self::Confirmed], 'to' => self::Done],
            'T06' => ['from' => [self::Proposed, self::AwaitingConfirmation, self::Confirmed], 'to' => self::Cancelled],
        ];
    }
}
