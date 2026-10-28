<?php

namespace App\Enums;

enum OrderLineType: string
{
    /** A made-to-order catalog product (its version and the chosen options are frozen). */
    case CustomProduct = 'custom_product';
    /** A free-text need on a custom order, before products are fixed (no catalog item). */
    case CustomNeed = 'custom_need';
    /** A ready-goods variant at its catalog price. */
    case ReadyVariant = 'ready_variant';

    public function kind(): OrderKind
    {
        return $this === self::ReadyVariant ? OrderKind::Ready : OrderKind::Custom;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
