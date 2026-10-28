<?php

namespace App\Settlement;

use App\Models\Order;
use App\Models\Returns\OrderReturn;
use App\Models\Settlement\SettlementItem;
use App\Models\User;
use App\Returns\Contracts\SettlementFollowUp;
use LogicException;

/**
 * Returns feed the order's one settlement follow-up (P7 plan «متابعة تسوية واحدة لكل طلب»): a return whose money still
 * needs settling adds one `return` item (source `return:{id}`, target = the return's settlement). The item is then
 * followed from the return itself (SettlementFollowUps::sync): done when the return's refund is executed, dropped when
 * the return ends without money. Both calls run inside the return's transaction, the order row locked first.
 */
final class ReturnSettlementFollowUp implements SettlementFollowUp
{
    public function __construct(private readonly SettlementFollowUps $settlement) {}

    public function open(Order $order, string $sourceType, int $sourceId, ?User $actor): void
    {
        self::assertReturn($sourceType);
        $return = OrderReturn::query()->findOrFail($sourceId);
        // Idempotent per return: a later call (inspection after acceptance) keeps the item, a settled one stays settled.
        $this->settlement->add($order, SettlementItem::RETURN, 'return:'.$sourceId, ['number' => $return->number],
            (int) $return->settlement_minor, $sourceType, $sourceId);
        $this->settlement->sync($order, $actor);
    }

    public function close(Order $order, string $sourceType, int $sourceId, ?User $actor): void
    {
        self::assertReturn($sourceType);
        $this->settlement->sync($order, $actor);
    }

    private static function assertReturn(string $sourceType): void
    {
        if ($sourceType !== 'order_return') {
            throw new LogicException("Unknown settlement source {$sourceType}.");
        }
    }
}
