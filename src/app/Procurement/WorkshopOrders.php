<?php

namespace App\Procurement;

use App\Enums\PurchaseStatus;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\Procurement\PurchaseOrder;
use App\Models\Procurement\PurchaseOrderLine;
use App\Models\Procurement\PurchaseReceipt;
use App\Models\User;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The purchase order is the only source of a workshop's progress (ADR-0038, closes W10–W12): after every M-13
 * transition the orders its lines are linked to are re-read, and
 *
 * - `workshop_order` (milestone `sent`) is verified when a matching purchase order is confirmed (T01);
 * - `workshop_order` (milestone `received`) and `supply_follow_up` are verified when every matching line is fully
 *   accepted — a rejected quantity never satisfies it, so a failed quality check keeps the action open;
 * - the receiving stage's `expected_on` follows the latest expected date of the matching orders (the stage's due).
 *
 * A purchase order matches an action by its `flow_reference`, or — without one — when it is the only action of its
 * kind on the order and the supplier's kind agrees (a workshop for `workshop_order`, any other for supplies). Called
 * inside the transition's transaction; ActionResults keeps a repeated signal from verifying twice.
 */
final class WorkshopOrders
{
    public const SOURCE_PURCHASE = 'purchase_order';

    public const SOURCE_RECEIPT = 'purchase_receipt';

    public function __construct(private readonly ActionResults $results) {}

    public function sync(PurchaseOrder $po, ?User $actor): void
    {
        $orderIds = PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->whereNotNull('order_id')
            ->distinct()->orderBy('order_id')->pluck('order_id');

        foreach ($orderIds as $id) {
            $order = Order::query()->find((int) $id);
            if ($order !== null) {
                $this->syncOrder($order, $actor);
            }
        }
    }

    public function syncOrder(Order $order, ?User $actor): void
    {
        $actions = OrderAction::query()->where('order_id', $order->id)
            ->whereIn('type', [ActionType::WorkshopOrder->value, ActionType::SupplyFollowUp->value])->get();
        if ($actions->isEmpty()) {
            return;
        }

        $lines = PurchaseOrderLine::query()->with('purchaseOrder.supplier')->where('order_id', $order->id)->get()
            ->filter(fn (PurchaseOrderLine $l) => $l->purchaseOrder->status !== PurchaseStatus::Draft)->values();

        foreach ($actions as $action) {
            $matching = self::matching($action, $actions, $lines);
            if ($matching->isEmpty()) {
                continue;
            }
            $reference = (string) ($action->inputs['reference'] ?? '');

            if ($action->type === ActionType::WorkshopOrder && ($action->inputs['milestone'] ?? null) === 'sent') {
                $first = $matching->map(fn (PurchaseOrderLine $l) => $l->purchaseOrder)->sortBy('id')->first();
                $this->results->verifyFromSource($order, ActionType::WorkshopOrder, $reference.':sent', self::SOURCE_PURCHASE, $first->id, $actor);

                continue;
            }

            if ($matching->every(fn (PurchaseOrderLine $l) => $l->remainingMilli() === 0)) {
                $receipt = PurchaseReceipt::query()->whereIn('purchase_order_id', $matching->pluck('purchase_order_id')->unique()->all())->max('id');
                if ($receipt !== null) {
                    $purpose = $action->type === ActionType::WorkshopOrder ? $reference.':received' : $reference;
                    $this->results->verifyFromSource($order, $action->type, $purpose, self::SOURCE_RECEIPT, (int) $receipt, $actor);
                }
            }
        }

        $this->expectedDates($order, $actions, $lines);
    }

    /**
     * Open purchase orders placed with a workshop or followed by an order's flow (the «orders at factories» set).
     *
     * @return Builder<PurchaseOrder>
     */
    public static function openQuery(): Builder
    {
        return PurchaseOrder::query()
            ->whereIn('status', [PurchaseStatus::Ordered->value, PurchaseStatus::PartiallyReceived->value])
            ->where(fn ($q) => $q->whereHas('supplier', fn ($s) => $s->where('is_workshop', true))
                ->orWhereNotNull('flow_reference')
                ->orWhereHas('lines', fn ($l) => $l->where('item_type', PurchaseOrderLine::ORDER_LINE)));
    }

    /**
     * Days a purchase order is overdue (0 when on time, closed or without a date).
     */
    public static function lateDays(PurchaseOrder $po, ?CarbonInterface $today = null): int
    {
        if (! $po->status->open() || $po->expected_on === null) {
            return 0;
        }
        $today ??= now()->startOfDay();

        return $po->expected_on->lt($today) ? (int) $po->expected_on->diffInDays($today) : 0;
    }

    /**
     * Lines of the order that this action follows.
     *
     * @param  Collection<int, OrderAction>  $actions
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @return Collection<int, PurchaseOrderLine>
     */
    public static function matching(OrderAction $action, Collection $actions, Collection $lines): Collection
    {
        $reference = (string) ($action->inputs['reference'] ?? '');
        $workshop = $action->type === ActionType::WorkshopOrder;
        $only = $actions->where('type', $action->type)
            ->map(fn (OrderAction $a) => (string) ($a->inputs['reference'] ?? ''))->unique()->count() === 1;

        return $lines->filter(function (PurchaseOrderLine $l) use ($reference, $workshop, $only) {
            $po = $l->purchaseOrder;
            if ($po->flow_reference !== null) {
                return $po->flow_reference === $reference;
            }

            return $only && $po->supplier->is_workshop === $workshop;
        })->values();
    }

    /**
     * The workshop stages' real due date: the latest expected date of the purchase orders they follow.
     *
     * @param  Collection<int, OrderAction>  $actions
     * @param  Collection<int, PurchaseOrderLine>  $lines
     */
    private function expectedDates(Order $order, Collection $actions, Collection $lines): void
    {
        $byStage = [];
        foreach ($actions->where('type', ActionType::WorkshopOrder) as $action) {
            $dates = self::matching($action, $actions, $lines)->map(fn (PurchaseOrderLine $l) => $l->purchaseOrder->expected_on)->filter();
            if ($dates->isNotEmpty() && ($action->inputs['milestone'] ?? null) === 'received') {
                $latest = $dates->max();
                $byStage[$action->order_stage_id] = isset($byStage[$action->order_stage_id]) ? max($byStage[$action->order_stage_id], $latest) : $latest;
            }
        }

        foreach ($byStage as $stageId => $date) {
            $stage = OrderStage::query()->where('order_id', $order->id)->find($stageId);
            if ($stage !== null && $stage->expected_on?->toDateString() !== $date->toDateString()) {
                $stage->forceFill(['expected_on' => $date->toDateString()])->save();
            }
        }
    }
}
