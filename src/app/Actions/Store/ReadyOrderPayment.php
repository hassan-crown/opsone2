<?php

namespace App\Actions\Store;

use App\Access\Exceptions\InvalidTransition;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T00CreateReservation;
use App\Actions\StateMachines\M24Reservation\T02ReleaseReservation;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrderLineType;
use App\Enums\ReadyOrderStatus;
use App\Enums\StateMachine;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Orders\OrderTransitions;
use Illuminate\Validation\ValidationException;

/**
 * The online-payment transitions of a ready order (M-05-T02..T07, WEB-06), run by the store and the payment service —
 * never by a browser request that carries a result. All inside the caller's transaction with the order locked.
 *
 * - T02: draft → awaiting payment; price and quantities fixed, every line reserved from the store location in one
 *   global lock order (two checkouts on the last unit: one reserves, the other is refused — scenario 3).
 * - T03: a verified payment while the reservation stands → confirmed.
 * - T04: the window ended with no money and no open transaction → cancelled, reservations released.
 * - T05: an unsettled or mismatching result → needs attention, the reservation is kept.
 * - T06: a verified payment after T04 → needs attention (the money is kept); T07 then re-reserves and confirms when
 *   every line is still available, otherwise it stays for a person (refund decision with P-43).
 */
final class ReadyOrderPayment
{
    public const SOURCE = 'store';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly T00CreateReservation $reserve,
        private readonly T02ReleaseReservation $release,
        private readonly TransitionRecorder $transitions,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function start(Order $order, array $context): void
    {
        OrderTransitions::check($order, 'T02');
        $numbers = $this->reserveAll($order);
        $this->move($order, 'T02', ReadyOrderStatus::Draft, ReadyOrderStatus::AwaitingPayment, null, $context + ['reservations' => $numbers]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function confirm(Order $order, array $context): void
    {
        OrderTransitions::check($order, 'T03');
        $this->move($order, 'T03', ReadyOrderStatus::AwaitingPayment, ReadyOrderStatus::Confirmed, null, $context);
        Audit::record(AuditAction::ReadyOrderConfirmed, $order, null, before: ['status' => ReadyOrderStatus::AwaitingPayment->value],
            after: ['status' => ReadyOrderStatus::Confirmed->value, 'channel' => 'store'], source: self::SOURCE, label: $order->number);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function cancel(Order $order, string $reason, array $context = []): void
    {
        OrderTransitions::check($order, 'T04');
        $released = $this->releaseAll($order, $reason);
        $this->move($order, 'T04', ReadyOrderStatus::AwaitingPayment, ReadyOrderStatus::Cancelled, $reason, $context + ['released' => $released]);
        Audit::record(AuditAction::ReadyOrderCancelled, $order, null, before: ['status' => ReadyOrderStatus::AwaitingPayment->value],
            after: ['status' => ReadyOrderStatus::Cancelled->value, 'released' => implode(', ', $released)], reason: $reason, source: self::SOURCE, label: $order->number);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function flag(Order $order, string $reason, array $context = []): void
    {
        OrderTransitions::check($order, 'T05');
        $this->move($order, 'T05', ReadyOrderStatus::AwaitingPayment, ReadyOrderStatus::NeedsAttention, $reason, $context);
    }

    /**
     * T06 then, when every line can be reserved again, T07 (system resolution). Returns whether the order is confirmed.
     *
     * @param  array<string, mixed>  $context
     */
    public function latePayment(Order $order, array $context): bool
    {
        OrderTransitions::check($order, 'T06');
        $this->move($order, 'T06', ReadyOrderStatus::Cancelled, ReadyOrderStatus::NeedsAttention, 'late_payment', $context);
        try {
            $numbers = $this->reserveAll($order);
        } catch (ValidationException) {
            return false; // sold meanwhile: the money stays for a person to settle (refund with P-43)
        }
        $this->move($order, 'T07', ReadyOrderStatus::NeedsAttention, ReadyOrderStatus::Confirmed, 'late_payment_reserved', $context + ['reservations' => $numbers]);
        Audit::record(AuditAction::ReadyOrderConfirmed, $order, null, before: ['status' => ReadyOrderStatus::NeedsAttention->value],
            after: ['status' => ReadyOrderStatus::Confirmed->value, 'channel' => 'store', 'late_payment' => true], source: self::SOURCE, label: $order->number);

        return true;
    }

    /**
     * T07 by the payment service when a flagged order's money is now confirmed and its reservation still stands.
     *
     * @param  array<string, mixed>  $context
     */
    public function resolveConfirmed(Order $order, array $context): void
    {
        OrderTransitions::check($order, 'T07');
        $this->move($order, 'T07', ReadyOrderStatus::NeedsAttention, ReadyOrderStatus::Confirmed, 'payment_confirmed', $context);
    }

    public function hasActiveReservations(Order $order): bool
    {
        return StockReservation::query()->where('order_id', $order->id)->where('status', 'active')->exists();
    }

    /**
     * @return list<string> reservation numbers
     */
    private function reserveAll(Order $order): array
    {
        $location = OrderStock::usableFor($order, null);
        /** @var list<array{line: OrderLine, item: StockItem, qty: int}> $wanted */
        $wanted = [];
        foreach ($order->activeLines()->get() as $line) {
            if ($line->line_type !== OrderLineType::ReadyVariant || $line->catalog_item_id === null) {
                continue;
            }
            $variant = CatalogReadyVariant::query()->with('product')->find($line->catalog_item_id);
            if ($variant === null || ! $variant->isSelectable()) {
                throw ValidationException::withMessages(["lines.{$line->id}" => __('stock.validation.variant_unavailable')]);
            }
            $wanted[] = ['line' => $line, 'item' => StockItems::resolve(StockItem::READY_VARIANT, $variant->id), 'qty' => Quantity::parse($line->quantity)];
        }
        if ($wanted === []) {
            throw ValidationException::withMessages(['lines' => __('stock.validation.no_ready_lines')]);
        }

        usort($wanted, fn (array $a, array $b) => [$a['item']->id, $a['line']->id] <=> [$b['item']->id, $b['line']->id]);
        $this->ledger->lockMany(array_map(fn (array $w) => [$location->id, $w['item']->id], $wanted));
        $need = [];
        foreach ($wanted as $w) {
            $need[$w['item']->id] = ($need[$w['item']->id] ?? 0) + $w['qty'];
        }
        foreach ($wanted as $w) {
            if ($this->ledger->lock($location->id, $w['item']->id)->availableMilli() < $need[$w['item']->id]) {
                throw ValidationException::withMessages(['lines' => __('checkout.validation.sold_out')]);
            }
        }

        $numbers = [];
        foreach ($wanted as $w) {
            $numbers[] = $this->reserve->handle($location, $w['item'], $w['qty'], $order, $w['line'], StockReservation::READY_ORDER, null, self::SOURCE, 'lines')->number;
        }

        return $numbers;
    }

    /**
     * @return list<string>
     */
    private function releaseAll(Order $order, string $reason): array
    {
        $released = [];
        foreach (StockReservation::query()->where('order_id', $order->id)->where('status', 'active')->orderBy('id')->get() as $reservation) {
            $released[] = $this->release->handle($reservation, null, $reason, self::SOURCE)->number;
        }

        return $released;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function move(Order $order, string $code, ReadyOrderStatus $from, ReadyOrderStatus $to, ?string $reason, array $context): void
    {
        if ($order->status !== $from->value) {
            throw new InvalidTransition(StateMachine::M05, $code, 'not_from_'.$order->status);
        }
        $order->status = $to->value;
        $order->lock_version++;
        $order->save();
        $this->transitions->record(StateMachine::M05, $code, $order, $from, $to, null, self::SOURCE, $reason, $context);
    }
}
