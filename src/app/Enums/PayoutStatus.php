<?php

namespace App\Enums;

/**
 * M-33 «صرف العمولة»: draft → reserved (T01, the lines are held) → paid (T02, proof of what left); draft or reserved →
 * cancelled (T03, the hold is released); paid → corrected (T04, P-97). T05 (a collection, refund, agreement change or
 * sale correction) re-derives the accrual and never changes a payout's state.
 */
enum PayoutStatus: string
{
    case Draft = 'draft';
    case Reserved = 'reserved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Corrected = 'corrected';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::Reserved],
            'T02' => ['from' => [self::Reserved], 'to' => self::Paid],
            'T03' => ['from' => [self::Draft, self::Reserved], 'to' => self::Cancelled],
            'T04' => ['from' => [self::Paid], 'to' => self::Corrected],
        ];
    }

    /** Lines of a reserved, paid or corrected payout are taken from the balance; a cancelled one released them. */
    public function holdsLines(): bool
    {
        return in_array($this, [self::Reserved, self::Paid, self::Corrected], true);
    }

    /** @return list<string> */
    public static function holding(): array
    {
        return [self::Reserved->value, self::Paid->value, self::Corrected->value];
    }
}
