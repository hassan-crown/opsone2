<?php

namespace App\Finance;

use App\Enums\DeliveryStatus;
use App\Enums\DueEvent;
use App\Enums\DueStatus;
use App\Models\Agreements\Agreement;
use App\Models\Delivery\DeliveryNote;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use App\Models\OrderAction;
use App\Workflow\AppointmentBooking;
use App\Workflow\Catalogue\ActionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When each due falls due, read from its source (FIN-02: a date or a clear event): the first agreement's approval,
 * the start of the flow stage that holds the payment check with the due's reference, or its date. Dates are in the
 * facility's time zone. Statuses are derived here and never stored.
 */
final class DueCalendar
{
    /**
     * @param  Collection<int, OrderDue>  $dues
     * @return array<int, string|null> due id → `Y-m-d`, null while its event has not happened
     */
    public static function dates(Order $order, Collection $dues): array
    {
        return self::datesFor(collect([$order->id => $order]), collect([$order->id => $dues]));
    }

    /**
     * The same dates for many orders with one query per kind of event, not per order (lists, ADR-0027).
     *
     * @template TDues of Collection<int, OrderDue>
     *
     * @param  Collection<array-key, Order>  $orders  keyed by id
     * @param  Collection<array-key, TDues>  $dues  keyed by order id
     * @return array<int, string|null> due id → `Y-m-d`
     */
    public static function datesFor(Collection $orders, Collection $dues): array
    {
        $timezone = AppointmentBooking::timezone();
        $needs = static fn (DueEvent $event): array => array_values(array_map(intval(...), $dues->filter(fn (Collection $d) => $d->contains(fn (OrderDue $due) => $due->due_event === $event))->keys()->all()));

        $agreed = self::firstAgreementDates($needs(DueEvent::Agreement), $timezone);
        $delivered = self::firstDeliveryDates($needs(DueEvent::Delivery));
        $stages = self::paymentStageStarts($needs(DueEvent::PaymentStage), $timezone);

        $dates = [];
        foreach ($dues as $orderId => $orderDues) {
            foreach ($orderDues as $due) {
                $dates[$due->id] = match ($due->due_event) {
                    DueEvent::Date => $due->due_date?->toDateString(),
                    DueEvent::Agreement => $agreed[$orderId] ?? null,
                    DueEvent::Delivery => $delivered[$orderId] ?? null,
                    DueEvent::PaymentStage => $stages[$orderId][$due->reference] ?? null,
                };
            }
        }

        return $dates;
    }

    public static function status(int $amount, int $allocated, ?string $dueOn, ?string $today = null): DueStatus
    {
        $today ??= AppointmentBooking::today();
        if ($allocated >= $amount) {
            return DueStatus::Paid;
        }
        if ($dueOn === null || $dueOn > $today) {
            return $allocated > 0 ? DueStatus::PartiallyPaid : DueStatus::Upcoming;
        }
        if ($dueOn < $today) {
            return DueStatus::Overdue;
        }

        return $allocated > 0 ? DueStatus::PartiallyPaid : DueStatus::Due;
    }

    /**
     * The first agreement's approval day per order (OrderAgreement::history()->first(): the lowest version).
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private static function firstAgreementDates(array $orderIds, string $timezone): array
    {
        if ($orderIds === []) {
            return [];
        }
        $dates = [];
        foreach (Agreement::query()->whereIn('order_id', $orderIds)->orderBy('version')->get(['order_id', 'approved_at']) as $agreement) {
            $dates[$agreement->order_id] ??= CarbonImmutable::parse($agreement->approved_at)->setTimezone($timezone)->toDateString();
        }

        return $dates;
    }

    /**
     * The date of each order's first confirmed delivery note (P7, ADR-0018): later deliveries never move it, so an
     * «at delivery» due falls due once.
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private static function firstDeliveryDates(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $dates = [];
        $notes = DeliveryNote::query()->whereIn('order_id', $orderIds)->where('status', DeliveryStatus::Confirmed->value)
            ->orderBy('delivered_on')->orderBy('id')->get(['id', 'order_id', 'delivered_on']);
        foreach ($notes as $note) {
            if ($note->delivered_on !== null) {
                $dates[$note->order_id] ??= $note->delivered_on->toDateString();
            }
        }

        return $dates;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array<string, string>> order id → payment check reference → the date its stage started
     */
    private static function paymentStageStarts(array $orderIds, string $timezone): array
    {
        if ($orderIds === []) {
            return [];
        }
        $starts = [];
        $actions = OrderAction::query()->with('stage')->whereIn('order_id', $orderIds)->where('type', ActionType::PaymentCheck->value)->get();
        foreach ($actions as $action) {
            $reference = (string) ($action->inputs['reference'] ?? '');
            $started = $action->stage->started_at;
            if ($reference !== '' && $started !== null) {
                $starts[$action->order_id][$reference] = CarbonImmutable::parse($started)->setTimezone($timezone)->toDateString();
            }
        }

        return $starts;
    }
}
