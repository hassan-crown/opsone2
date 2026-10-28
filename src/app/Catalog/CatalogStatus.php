<?php

namespace App\Catalog;

/**
 * Lifecycle of a catalog row. Nothing is deleted: `disabled` blocks new selection and keeps past usage.
 * `draft` and `published` apply to ready goods only; custom products and materials use `active`.
 */
enum CatalogStatus: string
{
    case Active = 'active';
    case Draft = 'draft';
    case Published = 'published';
    case Disabled = 'disabled';

    /**
     * Can it be chosen on new work (an order line, an option group)?
     */
    public function isSelectable(): bool
    {
        return $this === self::Active || $this === self::Published;
    }
}
