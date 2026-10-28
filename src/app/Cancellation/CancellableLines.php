<?php

namespace App\Cancellation;

use App\Agreements\OrderAgreement;
use App\Delivery\DeliveryProgress;
use App\Inventory\Quantity;
use App\Models\Cancellation\OrderCancellationLine;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Collection;

/**
 * What can still be cancelled on an order, line by line (FUL-12: «يفك غير المسلم فقط»):
 *
 * - a ready order: its ready lines; what is reserved and not issued (or, before confirmation, the ordered quantity);
 * - a custom order with an agreement: the agreement lines (`a{index}`), whose quantity minus what was delivered on the
 *   linked order line — the agreement carries the value, so cancelling there re-prices it through P6;
 * - a custom order without an agreement: whole order lines only (`l{id}`), there is no price to re-compute.
 *
 * Quantities in thousandths. Read under the order lock when it decides a write.
 */
final class CancellableLines
{
    /**
     * @return array<string, array{ref: string, order_line_id: int|null, agreement_line: int|null, description: string, unit: string|null, ordered_milli: int, delivered_milli: int, cancelled_milli: int, cancellable_milli: int, unit_price_minor: int|null, whole_only: bool}> by ref
     */
    public static function for(Order $order): array
    {
        $delivered = DeliveryProgress::deliveredByLine($order->id);
        $cancelled = self::cancelledByLine($order->id);
        $rows = [];

        if ($order->isReady()) {
            $reservations = StockReservation::query()->where('order_id', $order->id)->get()->groupBy('order_line_id');
            foreach (self::orderLines($order, true) as $line) {
                $ordered = Quantity::parse($line->quantity);
                /** @var Collection<int, StockReservation> $held */
                $held = $reservations->get($line->id) ?? new Collection;
                $done = $delivered[$line->id] ?? 0;
                $gone = $cancelled[$line->id] ?? 0;
                $cancellable = $held->isEmpty()
                    ? max(0, $ordered - $done - $gone)
                    : (int) $held->sum(fn (StockReservation $r) => $r->status->isOpen() ? $r->remainingMilli() : 0);
                $rows['l'.$line->id] = self::row('l'.$line->id, $line->id, null, self::name($line), $line->unit, $ordered, $done, $gone, $cancellable, $line->unit_price_minor, false);
            }

            return $rows;
        }

        $agreement = OrderAgreement::current($order);
        if ($agreement !== null) {
            $byAgreementLine = OrderCancellationLine::query()->where('order_cancellation_lines.order_id', $order->id)->whereNotNull('agreement_line')
                ->join('order_cancellations', 'order_cancellations.id', '=', 'order_cancellation_lines.order_cancellation_id')
                ->where('order_cancellations.status', 'accepted')
                ->groupBy('agreement_line')->selectRaw('agreement_line, SUM(quantity_milli) as total')->pluck('total', 'agreement_line')
                ->map(fn ($v) => (int) $v)->all();
            /** @var list<array<string, mixed>> $lines */
            $lines = $agreement->content['lines'] ?? [];
            foreach ($lines as $i => $line) {
                $quantity = Quantity::parse((string) $line['quantity']);
                $lineId = isset($line['order_line_id']) ? (int) $line['order_line_id'] : null;
                $done = $lineId === null ? 0 : min($quantity, $delivered[$lineId] ?? 0);
                $rows['a'.$i] = self::row('a'.$i, $lineId, $i, (string) $line['description'], isset($line['unit']) ? (string) $line['unit'] : null,
                    $quantity + ($byAgreementLine[$i] ?? 0), $done, $byAgreementLine[$i] ?? 0, max(0, $quantity - $done),
                    isset($line['unit_price_minor']) ? (int) $line['unit_price_minor'] : null, false);
            }

            return $rows;
        }

        foreach (self::orderLines($order, false) as $line) {
            $ordered = Quantity::parse($line->quantity);
            $done = $delivered[$line->id] ?? 0;
            $rows['l'.$line->id] = self::row('l'.$line->id, $line->id, null, self::name($line), $line->unit, $ordered, $done, 0, $done > 0 ? 0 : $ordered, null, true);
        }

        return $rows;
    }

    /**
     * Quantity accepted cancellations took off each order line (custom lines: the effective ordered quantity for
     * delivery is ordered − delivered − this).
     *
     * @return array<int, int>
     */
    public static function cancelledByLine(int $orderId): array
    {
        return OrderCancellationLine::query()->where('order_cancellation_lines.order_id', $orderId)->whereNotNull('order_line_id')
            ->join('order_cancellations', 'order_cancellations.id', '=', 'order_cancellation_lines.order_cancellation_id')
            ->where('order_cancellations.status', 'accepted')
            ->groupBy('order_line_id')->selectRaw('order_line_id, SUM(quantity_milli) as total')->pluck('total', 'order_line_id')
            ->map(fn ($v) => (int) $v)->all();
    }

    public static function name(OrderLine $line): string
    {
        $locale = app()->getLocale();
        $item = is_array($line->snapshot['item'] ?? null) ? $line->snapshot['item'] : [];
        $name = $item['name'] ?? null;
        $name = is_array($name) ? (string) ($name[$locale] ?? $name['ar'] ?? '') : (string) ($name ?? '');
        $description = trim($line->description !== null && $line->description !== '' ? ($name !== '' ? $name.' — '.$line->description : $line->description) : $name);

        return $description === '' ? '—' : mb_substr($description, 0, 500);
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private static function orderLines(Order $order, bool $ready): Collection
    {
        return $order->activeLines()->get()
            ->filter(fn (OrderLine $l) => ($l->line_type->value === 'ready_variant') === $ready)->values();
    }

    /**
     * @return array{ref: string, order_line_id: int|null, agreement_line: int|null, description: string, unit: string|null, ordered_milli: int, delivered_milli: int, cancelled_milli: int, cancellable_milli: int, unit_price_minor: int|null, whole_only: bool}
     */
    private static function row(string $ref, ?int $lineId, ?int $agreementLine, string $description, ?string $unit, int $ordered, int $delivered, int $cancelled, int $cancellable, ?int $price, bool $wholeOnly): array
    {
        return [
            'ref' => $ref, 'order_line_id' => $lineId, 'agreement_line' => $agreementLine, 'description' => $description, 'unit' => $unit,
            'ordered_milli' => $ordered, 'delivered_milli' => $delivered, 'cancelled_milli' => $cancelled, 'cancellable_milli' => $cancellable,
            'unit_price_minor' => $price, 'whole_only' => $wholeOnly,
        ];
    }
}
