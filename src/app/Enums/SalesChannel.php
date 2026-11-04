<?php

namespace App\Enums;

/**
 * Where an order was sold (ADR-0041): set when the order is created — the store's own orders are `online_store`, an
 * order a field sales rep opens (or converts from a lead dispatched to a rep) is `field_sales`, anything else is
 * `showroom` — and changed afterwards only with P-106, audited.
 */
enum SalesChannel: string
{
    case Showroom = 'showroom';
    case OnlineStore = 'online_store';
    case FieldSales = 'field_sales';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
