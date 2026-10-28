<?php

namespace App\Procurement;

/**
 * Stock locations offered when choosing where a purchase lands. The stock domain owns locations; procurement
 * references them by id only. Until the stock side supplies a directory this returns none, and the screens fall back to
 * a plain location id field.
 */
final class Locations
{
    /**
     * @return list<array{id: int, name: string}>
     */
    public static function options(): array
    {
        return [];
    }
}
