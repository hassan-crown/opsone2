<?php

namespace App\Enums;

/**
 * M-13 «طلب الشراء» (FUL-05..07). Ordered = «مطلوب». Remaining = ordered − accepted: a rejected or damaged quantity
 * never satisfies the order. Cancelling the remainder keeps everything received.
 */
enum PurchaseStatus: string
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case CancelledRemainder = 'cancelled_remainder';

    /**
     * @return array<string, array{from: list<self>, to: self|null}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::Ordered],
            'T02' => ['from' => [self::Ordered, self::PartiallyReceived], 'to' => null],
            'T03' => ['from' => [self::Draft, self::Ordered, self::PartiallyReceived], 'to' => self::CancelledRemainder],
            'T04' => ['from' => [self::Ordered, self::PartiallyReceived], 'to' => null],
        ];
    }

    /** Still waiting for goods: receipts and quantity changes are allowed. */
    public function open(): bool
    {
        return in_array($this, [self::Ordered, self::PartiallyReceived], true);
    }
}
