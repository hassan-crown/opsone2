<?php

namespace App\Enums;

/**
 * M-11 «معاملة التحصيل». Staff-recorded and proof-confirmed payments go recorded → confirmed in one action (T00+T01);
 * online payments start pending_confirmation and only a verified gateway event moves them (T02..T05).
 */
enum PaymentStatus: string
{
    case Recorded = 'recorded';
    case PendingConfirmation = 'pending_confirmation';
    case Unresolved = 'unresolved';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Corrected = 'corrected';

    /**
     * @return array<string, array{from: list<self>, to: list<self>}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Recorded], 'to' => [self::Confirmed]],
            'T02' => ['from' => [self::PendingConfirmation], 'to' => [self::Confirmed]],
            'T03' => ['from' => [self::PendingConfirmation], 'to' => [self::Unresolved]],
            'T04' => ['from' => [self::Unresolved], 'to' => [self::Confirmed, self::Failed]],
            'T05' => ['from' => [self::PendingConfirmation], 'to' => [self::Failed]],
            'T06' => ['from' => [self::Recorded, self::PendingConfirmation], 'to' => [self::Cancelled]],
            // P6c: a documented correction of a confirmed payment.
            'T07' => ['from' => [self::Confirmed], 'to' => [self::Corrected]],
        ];
    }

    /** Money that really arrived: it has a receipt and journal entries. */
    public function isMoney(): bool
    {
        return $this === self::Confirmed || $this === self::Corrected;
    }
}
