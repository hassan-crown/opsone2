<?php

namespace App\Enums;

/**
 * M-14 «تسليم الطلب»: draft → confirmed (T01), draft → cancelled before confirmation (T02). A confirmed note is never
 * edited or deleted; a mistake is corrected by a linked correction (T03, `delivery_corrections`, ADR-0035) that keeps
 * the note confirmed.
 */
enum DeliveryStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::Confirmed],
            'T02' => ['from' => [self::Draft], 'to' => self::Cancelled],
        ];
    }
}
