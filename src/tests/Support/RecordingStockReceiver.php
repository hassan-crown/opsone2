<?php

namespace Tests\Support;

use App\Inventory\Contracts\StockReceiver;

/**
 * Test double of the stock domain's receive side (the real one is bound by the stock session): records every call.
 */
final class RecordingStockReceiver implements StockReceiver
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    public function receive(int $locationId, string $itemType, int $itemId, int $quantityMilli, string $sourceType, int $sourceId, ?int $actorId, string $idempotencyKey): void
    {
        $this->calls[] = compact('locationId', 'itemType', 'itemId', 'quantityMilli', 'sourceType', 'sourceId', 'actorId', 'idempotencyKey');
    }
}
