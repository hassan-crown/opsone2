<?php

namespace App\Returns;

use App\Models\Order;
use App\Models\User;
use App\Returns\Contracts\SettlementFollowUp;

/**
 * Placeholder until the cancellation session's settlement follow-up is merged: the return itself stays the visible
 * open item (returns list «settlement open», commission hold `settlement`).
 */
final class NoSettlementFollowUp implements SettlementFollowUp
{
    public function open(Order $order, string $sourceType, int $sourceId, ?User $actor): void {}

    public function close(Order $order, string $sourceType, int $sourceId, ?User $actor): void {}
}
