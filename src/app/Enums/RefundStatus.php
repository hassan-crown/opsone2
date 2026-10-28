<?php

namespace App\Enums;

/**
 * M-12 «الاسترداد» (FIN-09). Requested, in progress and unresolved refunds reserve their amount from what the payment
 * may still give back; only an executed refund reduces the net collected (once). In v1 refunds are paid outside the
 * system and recorded manually with proof (Q-42); T03/T05 are reserved for a provider in P8.
 */
enum RefundStatus: string
{
    case Requested = 'requested';
    case InProgress = 'in_progress';
    case Unresolved = 'unresolved';
    case Executed = 'executed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Requested], 'to' => self::InProgress],
            'T02' => ['from' => [self::InProgress], 'to' => self::Executed],
            'T03' => ['from' => [self::InProgress], 'to' => self::Unresolved],
            'T04' => ['from' => [self::InProgress, self::Unresolved], 'to' => self::Failed],
            'T05' => ['from' => [self::Unresolved], 'to' => self::Executed],
            'T06' => ['from' => [self::Requested], 'to' => self::Cancelled],
            'T07' => ['from' => [self::Failed], 'to' => self::InProgress],
        ];
    }

    /** Holds part of the refundable limit without having reduced the money yet (FIN-05). */
    public function reserves(): bool
    {
        return in_array($this, [self::Requested, self::InProgress, self::Unresolved], true);
    }
}
