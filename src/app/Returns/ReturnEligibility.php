<?php

namespace App\Returns;

use App\Delivery\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\ReturnStatus;
use App\Inventory\Quantity;
use App\Models\Delivery\DeliveryNoteLine;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Returns\OrderReturnLine;
use App\Money\Rounding;
use Carbon\CarbonImmutable;

/**
 * What may still be asked back per delivered order line (SRS-INT-03): eligible = delivered − accepted by returns that
 * hold eligibility (accepted, received, inspected — received is part of accepted, so nothing is subtracted twice). A
 * request alone holds nothing; a rejection or a withdrawal before receipt frees the quantity. Read under the order
 * lock when it decides a write, so two acceptances never exceed what was delivered.
 */
final class ReturnEligibility
{
    /**
     * @return array<int, array{line: OrderLine, delivered: int, held: int, eligible: int, delivered_on: string|null}> by order line id
     */
    public static function lines(Order $order, ?int $exceptReturnId = null): array
    {
        if (! $order->isReady()) {
            return [];
        }
        $delivered = DeliveryProgress::deliveredByLine($order->id);
        $held = self::heldByLine($order->id, $exceptReturnId);
        $dates = self::lastDeliveredOn($order->id);

        $rows = [];
        foreach ($order->lines()->get() as $line) {
            /** @var OrderLine $line */
            $done = $delivered[$line->id] ?? 0;
            if ($done === 0) {
                continue;
            }
            $hold = $held[$line->id] ?? 0;
            $rows[$line->id] = ['line' => $line, 'delivered' => $done, 'held' => $hold, 'eligible' => max(0, $done - $hold), 'delivered_on' => $dates[$line->id] ?? null];
        }

        return $rows;
    }

    /**
     * @return array<int, int>
     */
    public static function heldByLine(int $orderId, ?int $exceptReturnId = null): array
    {
        $holding = array_map(fn (ReturnStatus $s) => $s->value, array_values(array_filter(ReturnStatus::cases(), fn (ReturnStatus $s) => $s->holdsEligibility())));

        return OrderReturnLine::query()
            ->join('order_returns', 'order_returns.id', '=', 'order_return_lines.order_return_id')
            ->where('order_returns.order_id', $orderId)->whereIn('order_returns.status', $holding)
            ->when($exceptReturnId !== null, fn ($q) => $q->where('order_returns.id', '!=', $exceptReturnId))
            ->groupBy('order_return_lines.order_line_id')
            ->selectRaw('order_return_lines.order_line_id as line_id, SUM(order_return_lines.accepted_milli) as total')
            ->pluck('total', 'line_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Date of the latest confirmed delivery of each line (the start of the return window).
     *
     * @return array<int, string>
     */
    public static function lastDeliveredOn(int $orderId): array
    {
        return DeliveryNoteLine::query()
            ->join('delivery_notes', 'delivery_notes.id', '=', 'delivery_note_lines.delivery_note_id')
            ->where('delivery_notes.order_id', $orderId)->where('delivery_notes.status', DeliveryStatus::Confirmed->value)
            ->groupBy('delivery_note_lines.order_line_id')
            ->selectRaw('delivery_note_lines.order_line_id as line_id, MAX(delivery_notes.delivered_on) as last_on')
            ->pluck('last_on', 'line_id')->map(fn ($v) => CarbonImmutable::parse((string) $v)->toDateString())->all();
    }

    /**
     * Whether a delivery date is past the window today (indicator only — never a refusal).
     */
    public static function overdue(?string $deliveredOn, int $windowDays): bool
    {
        return $deliveredOn !== null && CarbonImmutable::parse($deliveredOn)->addDays($windowDays)->lt(CarbonImmutable::today());
    }

    /**
     * Value of a quantity of a line as sold (its share of the line total, VAT and discount included), half-up once.
     */
    public static function valueOf(OrderLine $line, int $quantityMilli): ?int
    {
        $ordered = Quantity::parse($line->quantity);
        if ($line->line_total_minor !== null && $ordered > 0) {
            return Rounding::divide(bcmul((string) $line->line_total_minor, (string) $quantityMilli), (string) $ordered);
        }
        if ($line->unit_price_minor !== null) {
            return Rounding::divide(bcmul((string) $line->unit_price_minor, (string) $quantityMilli), '1000');
        }

        return null;
    }
}
