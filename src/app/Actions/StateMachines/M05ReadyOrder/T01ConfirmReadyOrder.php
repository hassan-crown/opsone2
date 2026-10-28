<?php

namespace App\Actions\StateMachines\M05ReadyOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T00CreateReservation;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\OrderLineType;
use App\Enums\ReadyOrderStatus;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Inventory\StockItems;
use App\Inventory\StockLedger;
use App\Models\Catalog\CatalogReadyVariant;
use App\Models\Inventory\StockItem;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use Illuminate\Validation\ValidationException;

/**
 * M-05-T01 (P-15 + `ready_goods`): confirm a ready draft with later payment. Every active line is reserved from one
 * usable location in the same transaction, after locking the order and then all the balance rows in one global
 * order; a line short of stock refuses the whole confirmation (FUL-02, scenario 3 «بيعان على آخر قطعة»). A cart or
 * draft never reserves.
 */
final class T01ConfirmReadyOrder
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly T00CreateReservation $reserve,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Order $order, ?int $locationId = null, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        return LockingTransaction::run(function () use ($actor, $order, $locationId, $lockVersion, $source) {
            $order = OrderStock::lockOrder($order->id, $lockVersion);
            Access::authorize($actor, Permission::P15, $order);
            OrderTransitions::check($order, 'T01');
            $location = OrderStock::usableFor($order, $locationId);

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
            $this->assertEnough($location->id, $wanted);

            $numbers = [];
            foreach ($wanted as $w) {
                $numbers[] = $this->reserve->handle($location, $w['item'], $w['qty'], $order, $w['line'], StockReservation::READY_ORDER, $actor, $source, "lines.{$w['line']->id}")->number;
            }

            $order->status = ReadyOrderStatus::Confirmed->value;
            $order->lock_version++;
            $order->save();
            $this->transitions->record($order->kind->machine(), 'T01', $order, ReadyOrderStatus::Draft, ReadyOrderStatus::Confirmed, $actor, $source, null, [
                'location_id' => $location->id, 'reservations' => $numbers,
            ]);
            Audit::record(AuditAction::ReadyOrderConfirmed, $order, $actor, before: ['status' => ReadyOrderStatus::Draft->value],
                after: ['status' => ReadyOrderStatus::Confirmed->value, 'location' => $location->code, 'reservations' => implode(', ', $numbers)], source: $source, label: $order->number);

            return $order;
        });
    }

    /**
     * The same variant on two lines needs their sum; reported per line so the screen marks the short one.
     *
     * @param  list<array{line: OrderLine, item: StockItem, qty: int}>  $wanted
     */
    private function assertEnough(int $locationId, array $wanted): void
    {
        $need = [];
        foreach ($wanted as $w) {
            $need[$w['item']->id] = ($need[$w['item']->id] ?? 0) + $w['qty'];
        }
        foreach ($wanted as $w) {
            $available = $this->ledger->lock($locationId, $w['item']->id)->availableMilli();
            if ($available < $need[$w['item']->id]) {
                throw ValidationException::withMessages(["lines.{$w['line']->id}" => __('stock.validation.insufficient', ['available' => Quantity::format(max(0, $available))])]);
            }
        }
    }
}
