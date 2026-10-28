<?php

namespace App\Returns\Contracts;

use App\Models\Order;
use App\Models\User;

/**
 * «متابعة تسوية واحدة لكل طلب» (P7 plan): the order's single settlement follow-up that cancellations and returns
 * feed. Bound to App\Settlement\ReturnSettlementFollowUp; returns only consume it through this seam.
 */
interface SettlementFollowUp
{
    /**
     * Registers (idempotently, per source) that the order has a return whose money still needs a decision.
     */
    public function open(Order $order, string $sourceType, int $sourceId, ?User $actor): void;

    /**
     * The source changed (refund requested, part released, return closed without money): the follow-up re-reads it and
     * settles the item once nothing is left to do.
     */
    public function close(Order $order, string $sourceType, int $sourceId, ?User $actor): void;
}
