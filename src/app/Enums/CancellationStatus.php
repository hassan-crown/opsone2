<?php

namespace App\Enums;

/**
 * M-34 «طلب إلغاء العميل». The request is the reference of the decision; the order (M-04 / M-05) shows its effect.
 */
enum CancellationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    /**
     * Staff record the request (T01, external source) or record and accept it at once (T03). T05 (customer withdrawal,
     * P-99) belongs to the customer portal (P8).
     *
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T02' => ['from' => [self::Pending], 'to' => self::Accepted],
            'T03' => ['from' => [self::Pending], 'to' => self::Accepted],
            'T04' => ['from' => [self::Pending], 'to' => self::Rejected],
            'T05' => ['from' => [self::Pending], 'to' => self::Withdrawn],
        ];
    }
}
