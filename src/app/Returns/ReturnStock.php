<?php

namespace App\Returns;

use App\Inventory\MovementKind;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockMovement;
use App\Models\OrderLine;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnPart;
use App\Models\User;

/**
 * Stock side of an inspection (FUL-06/FUL-11, INT-02): only the good quantity is posted back to the receiving
 * location (`return_in`, available at once); damaged goods get a custody row (`return_damaged`, no balance effect) so
 * the journal shows where they are; rejected goods are handed back and move nothing. Every movement carries the return
 * number, `source_type = order_return_part` and a key `return:{part}:{what}[:{n}]`, so a replay never posts twice.
 */
final class ReturnStock
{
    public function __construct(private readonly StockLedger $ledger) {}

    public static function itemFor(OrderLine $line): StockItem
    {
        return StockItems::resolve(StockItem::READY_VARIANT, (int) $line->catalog_item_id);
    }

    /**
     * Locks every balance the parts will touch, in the ledger's global order.
     *
     * @param  iterable<OrderReturnPart>  $parts
     */
    public function lockFor(iterable $parts): void
    {
        $pairs = [];
        foreach ($parts as $part) {
            $pairs[] = [$part->location_id, self::itemFor($part->line->orderLine)->id];
        }
        $this->ledger->lockMany($pairs);
    }

    public function post(MovementKind $kind, OrderReturn $return, OrderReturnPart $part, int $quantityMilli, User $actor, string $key, ?string $reason = null): void
    {
        if ($quantityMilli <= 0 || StockMovement::query()->where('idempotency_key', $key)->exists()) {
            return;
        }
        $balance = $this->ledger->lock($part->location_id, self::itemFor($part->line->orderLine)->id);
        $this->ledger->post($kind, $balance, $quantityMilli, [
            'document_number' => $return->number, 'order_id' => $return->order_id, 'source_type' => 'order_return_part',
            'source_id' => $part->id, 'reason' => $reason, 'actor_id' => $actor->id, 'idempotency_key' => $key,
        ]);
    }
}
