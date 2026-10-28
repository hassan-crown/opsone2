<?php

namespace App\Enums;

/**
 * M-26 «قرار الخصم أو السماح بالمتابعة» — the discount part (AGR-02). `superseded` is T04's «approved historically»:
 * the decision is kept but no longer covers the changed content.
 */
enum DiscountDecisionStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Requested], 'to' => self::Approved],
            'T02' => ['from' => [self::Requested], 'to' => self::Rejected],
            'T03' => ['from' => [self::Requested], 'to' => self::Cancelled],
            'T04' => ['from' => [self::Approved], 'to' => self::Superseded],
        ];
    }
}
