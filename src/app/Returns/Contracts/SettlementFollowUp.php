<?php

namespace App\Returns\Contracts;

use App\Models\Order;
use App\Models\User;

/**
 * «متابعة تسوية واحدة لكل طلب» (P7 plan): the order's single settlement follow-up that cancellations and returns
 * feed. Built by the cancellation session (CancelReview); returns only consume it through this seam.
 *
 * TODO(p7-merge): bind the cancellation session's follow-up here and delete `NoSettlementFollowUp`.
 */
interface SettlementFollowUp
{
    /**
     * Registers (idempotently, per source) that the order has a return whose money still needs a decision.
     */
    public function open(Order $order, string $sourceType, int $sourceId, ?User $actor): void;

    /**
     * The source no longer needs settlement (refund requested, return closed without money).
     */
    public function close(Order $order, string $sourceType, int $sourceId, ?User $actor): void;
}
