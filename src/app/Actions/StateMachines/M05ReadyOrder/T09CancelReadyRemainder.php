<?php

namespace App\Actions\StateMachines\M05ReadyOrder;

use App\Access\Access;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Actions\StateMachines\M24Reservation\T02ReleaseReservation;
use App\Audit\Audit;
use App\Audit\AuditAction;
use App\Enums\ReadyOrderStatus;
use App\Inventory\OrderStock;
use App\Inventory\StockLedger;
use App\Models\Inventory\StockReservation;
use App\Models\Order;
use App\Models\User;
use App\Orders\OrderTransitions;
use App\Support\LockingTransaction;
use App\Workflow\WorkGuard;
use LogicException;

/**
 * M-05-T09 (P-18): cancel what is not delivered. Unissued reservations are released (M-24-T02/T03); what was issued
 * stays issued and never returns to stock without a return (FUL-02, scenario 6). The order becomes `cancelled` when
 * nothing was delivered, otherwise `delivered` for the effective (delivered) quantity. Runs under the order lock, so a
 * concurrent delivery either commits first (and stays) or sees the released reservation (scenario 4).
 */
final class T09CancelReadyRemainder
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly T02ReleaseReservation $release,
        private readonly TransitionRecorder $transitions,
    ) {}

    public function handle(User $actor, Order $order, string $reason, ?int $lockVersion = null, string $source = TransitionRecorder::SOURCE_WEB): Order
    {
        $reason = WorkGuard::reason($reason);

        return LockingTransaction::run(function () use ($actor, $order, $reason, $lockVersion, $source) {
            $order = OrderStock::lockOrder($order->id, $lockVersion);
            if (! $order->isReady()) {
                throw new LogicException('M-05-T09 cancels a ready order only.');
            }
            Access::authorize($actor, Permission::P18, $order);
            OrderTransitions::check($order, 'T09');
            $from = $order->state();

            $reservations = OrderStock::reservations($order);
            $open = $reservations->filter(fn (StockReservation $r) => $r->status->isOpen())
                ->sortBy(fn (StockReservation $r) => sprintf('%012d:%012d:%012d', $r->location_id, $r->stock_item_id, $r->id))->values();
            $this->ledger->lockMany($open->map(fn (StockReservation $r) => [$r->location_id, $r->stock_item_id])->all());
            $released = [];
            foreach ($open as $reservation) {
                $released[] = $this->release->handle($reservation, $actor, $reason, $source)->number;
            }

            // Re-read after the row locks: a delivery that committed meanwhile counts as delivered.
            $delivered = StockReservation::query()->where('order_id', $order->id)->sum('issued_milli') > 0;
            $to = $delivered ? ReadyOrderStatus::Delivered : ReadyOrderStatus::Cancelled;
            $order->status = $to->value;
            $order->lock_version++;
            $order->save();

            $this->transitions->record($order->kind->machine(), 'T09', $order, $from, $to, $actor, $source, $reason, ['released' => $released]);
            Audit::record(AuditAction::ReadyOrderCancelled, $order, $actor, before: ['status' => $from->value],
                after: ['status' => $to->value, 'released' => implode(', ', $released)], reason: $reason, source: $source, label: $order->number);

            return $order;
        });
    }
}
