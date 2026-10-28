<?php

namespace App\Inventory\Contracts;

interface StockReceiver
{
    /**
     * Append a receipt movement into a location. Quantity is in thousandths of the item's unit.
     * Must be idempotent per $idempotencyKey and run inside the caller's transaction.
     */
    public function receive(int $locationId, string $itemType, int $itemId, int $quantityMilli, string $sourceType, int $sourceId, ?int $actorId, string $idempotencyKey): void;
}
