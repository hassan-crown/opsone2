<?php

namespace App\Returns;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnStatus;
use App\Finance\Settlement\RefundLimits;
use App\Inventory\OrderStock;
use App\Inventory\Quantity;
use App\Models\Finance\Payment;
use App\Models\Finance\Refund;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\Returns\OrderReturnPart;
use App\Models\Returns\ReturnSettings;
use App\Models\User;

/**
 * Returns shaped for the Return* screens. Quantities are decimal text; every amount (`*_minor`) is present only with
 * P-37 on the order — the keys are omitted otherwise, never sent as 0 (ADR-0019).
 */
final class ReturnPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function orderRef(Order $order): array
    {
        return [
            'id' => $order->id, 'number' => $order->number, 'status' => $order->status,
            'customer' => $order->customer->name, 'branch_id' => $order->branch_id,
        ];
    }

    /**
     * Delivered lines of an order and what each may still be asked back.
     *
     * @return list<array<string, mixed>>
     */
    public static function eligible(Order $order, User $viewer): array
    {
        $amounts = ReturnAccess::canSeeAmounts($viewer, $order);
        $window = ReturnSettings::current()->window_days;

        return array_values(array_map(fn (array $r) => self::line($r['line'], $amounts) + [
            'delivered' => Quantity::format($r['delivered']), 'held' => Quantity::format($r['held']),
            'eligible' => Quantity::format($r['eligible']), 'delivered_on' => $r['delivered_on'],
            'overdue' => ReturnEligibility::overdue($r['delivered_on'], $window),
        ], ReturnEligibility::lines($order)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(OrderReturn $return, bool $amounts): array
    {
        return [
            'id' => $return->id, 'number' => $return->number, 'status' => $return->status->value,
            'order' => ['id' => $return->order->id, 'number' => $return->order->number],
            'customer' => $return->order->customer->name, 'reason' => $return->reason,
            'created_at' => $return->created_at->toIso8601String(),
            'refund_asked' => $return->refund_id !== null,
        ] + ($amounts && $return->settlement_minor !== null ? ['settlement_minor' => $return->settlement_minor] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(OrderReturn $return, User $viewer): array
    {
        $return->load(['order.customer', 'lines.orderLine', 'parts.location', 'parts.receiver', 'requester', 'decider']);
        $order = $return->order;
        $amounts = ReturnAccess::canSeeAmounts($viewer, $order);
        $status = $return->status;
        $parts = $return->parts;
        $dates = ReturnEligibility::lastDeliveredOn($order->id);
        $eligible = $status === ReturnStatus::Requested ? ReturnEligibility::lines($order, $return->id) : [];
        $refund = $return->refund_id === null ? null : Refund::query()->find($return->refund_id);
        $lastDelivered = collect($return->lines)->map(fn (OrderReturnLine $l) => $dates[$l->order_line_id] ?? null)->filter()->max();

        $receive = ReturnAccess::canReceive($viewer, $order) && in_array($status, [ReturnStatus::Accepted, ReturnStatus::Received], true)
            && $return->lines->sum(fn (OrderReturnLine $l) => $l->outstandingMilli()) > 0;
        $inspect = ReturnAccess::canReceive($viewer, $order) && $parts->contains(fn (OrderReturnPart $p) => ! $p->isInspected());
        $decide = ReturnAccess::canDecide($viewer, $order);
        $refundable = ReturnAccess::canRefund($viewer, $order) && $amounts && $status->holdsEligibility() && (int) $return->settlement_minor > 0
            && ($refund === null || ! ($refund->status->reserves() || $refund->status === RefundStatus::Executed));

        return self::row($return, $amounts) + [
            'source' => $return->source, 'lock_version' => $return->lock_version,
            'order' => self::orderRef($order),
            'requested_by' => $return->requester?->name,
            'decision_reason' => $return->decision_reason, 'decided_by' => $return->decider?->name, 'decided_at' => $return->decided_at?->toIso8601String(),
            'cancel_reason' => $return->cancel_reason,
            'window_days' => $return->window_days, 'delivered_on' => $lastDelivered,
            'overdue' => ReturnEligibility::overdue($lastDelivered, $return->window_days),
            'lines' => $return->lines->map(function (OrderReturnLine $l) use ($amounts, $parts, $eligible) {
                $own = $parts->where('order_return_line_id', $l->id);

                return self::line($l->orderLine, $amounts) + [
                    'requested' => Quantity::format($l->requested_milli), 'accepted' => Quantity::format($l->accepted_milli),
                    'received' => Quantity::format($l->received_milli), 'outstanding' => Quantity::format($l->outstandingMilli()),
                    'good' => Quantity::format((int) $own->sum('good_milli')), 'damaged' => Quantity::format((int) $own->sum('damaged_milli')),
                    'rejected' => Quantity::format((int) $own->sum('rejected_milli')),
                    'eligible' => isset($eligible[$l->order_line_id]) ? Quantity::format($eligible[$l->order_line_id]['eligible']) : null,
                ] + ($amounts && $l->accepted_milli > 0 ? ['value_minor' => ReturnEligibility::valueOf($l->orderLine, $l->accepted_milli)] : []);
            })->values()->all(),
            'parts' => $parts->map(fn (OrderReturnPart $p) => [
                'id' => $p->id, 'order_line_id' => $return->lines->firstWhere('id', $p->order_return_line_id)?->order_line_id,
                'location' => ['id' => $p->location->id, 'code' => $p->location->code, 'name' => $p->location->name->get()],
                'quantity' => Quantity::format($p->quantity_milli), 'received_at' => $p->received_at->toIso8601String(),
                'received_by' => $p->receiver?->name, 'inspected' => $p->isInspected(),
                'good' => $p->good_milli === null ? null : Quantity::format($p->good_milli),
                'damaged' => $p->damaged_milli === null ? null : Quantity::format($p->damaged_milli),
                'rejected' => $p->rejected_milli === null ? null : Quantity::format($p->rejected_milli),
                'inspection_note' => $p->inspection_note,
                'can_correct' => $p->isInspected() && ReturnAccess::canReceive($viewer, $order) && Access::can($viewer, Permission::P49, $p->location),
            ])->values()->all(),
            'settlement' => $amounts ? ['value_minor' => $return->value_minor, 'settlement_minor' => $return->settlement_minor] : null,
            'refund' => $refund === null ? null : ['id' => $refund->id, 'number' => $refund->number, 'status' => $refund->status->value] + ($amounts ? ['amount_minor' => $refund->amount_minor] : []),
            'payments' => $refundable ? self::payments($order) : [],
            'locations' => $receive ? self::locations($viewer, $order) : [],
            'can' => [
                'accept' => $status === ReturnStatus::Requested && $decide,
                'reject' => $status === ReturnStatus::Requested && $decide,
                'withdraw' => ($status === ReturnStatus::Requested && ($decide || ($return->requested_by === $viewer->id && ReturnAccess::canRequest($viewer, $order))))
                    || (in_array($status, [ReturnStatus::Accepted, ReturnStatus::Received], true) && $decide && $return->lines->sum(fn (OrderReturnLine $l) => $l->outstandingMilli()) > 0),
                'receive' => $receive,
                'inspect' => $inspect,
                'refund' => $refundable,
            ],
        ];
    }

    /**
     * @return array<int, array{id: int, receipt_number: string|null, refundable_minor: int}>
     */
    private static function payments(Order $order): array
    {
        return Payment::query()->with('receipt')->where('order_id', $order->id)->where('status', PaymentStatus::Confirmed->value)->orderBy('id')->get()
            ->map(fn (Payment $p) => ['id' => $p->id, 'receipt_number' => $p->receipt?->number, 'refundable_minor' => RefundLimits::refundable($p, $order->id)->minor])
            ->values()->all();
    }

    /**
     * @return array<int, array{id: int, code: string, name: string|null}>
     */
    private static function locations(User $viewer, Order $order): array
    {
        $allowed = ReturnAccess::locations($viewer)->pluck('id')->all();

        return OrderStock::locationsFor($order)->filter(fn (StockLocation $l) => in_array($l->id, $allowed, true))
            ->map(fn (StockLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => $l->name->get()])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(OrderLine $line, bool $amounts): array
    {
        $item = is_array($line->snapshot['item'] ?? null) ? $line->snapshot['item'] : [];
        $name = $item['name'] ?? null;

        return [
            'order_line_id' => $line->id, 'position' => $line->position,
            'name' => is_array($name) ? (string) ($name[app()->getLocale()] ?? $name['ar'] ?? $name['en'] ?? '') : ($line->description ?? ''),
            'unit' => $line->unit,
        ] + ($amounts && $line->unit_price_minor !== null ? ['unit_price_minor' => $line->unit_price_minor] : []);
    }
}
