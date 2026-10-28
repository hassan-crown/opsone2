<?php

namespace App\Returns;

use App\Commissions\SettlementHolds;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Models\Returns\OrderReturn;

/**
 * SettlementHolds decorated by returns (SRS-INT-06): while a return of the order is undecided, expected or under
 * inspection, or accepted with a settlement amount and no refund asked yet, the order's unpaid commission is held
 * (`settlement`) — the accrual is still recorded and the member's other orders stay payable.
 */
final class ReturnSettlementHolds implements SettlementHolds
{
    public function __construct(private readonly SettlementHolds $inner) {}

    public function holdOf(Order $order): ?string
    {
        return $this->inner->holdOf($order) ?? (self::open($order->id) ? 'settlement' : null);
    }

    public static function open(int $orderId): bool
    {
        $open = array_map(fn (ReturnStatus $s) => $s->value, array_values(array_filter(ReturnStatus::cases(), fn (ReturnStatus $s) => $s->isOpen())));

        return OrderReturn::query()->where('order_id', $orderId)->where(fn ($q) => $q->whereIn('status', $open)
            ->orWhere(fn ($w) => $w->where('status', ReturnStatus::Inspected->value)->where('settlement_minor', '>', 0)->whereNull('refund_id')))
            ->exists();
    }
}
