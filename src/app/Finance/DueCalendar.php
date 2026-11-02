<?php

namespace App\Finance;

use App\Enums\DeliveryStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\DueEvent;
use App\Enums\DueStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementDocument;
use App\Models\Delivery\DeliveryNote;
use App\Models\Finance\OrderDue;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Workflow\AppointmentBooking;
use App\Workflow\Catalogue\ActionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * When each due falls due, read from its source (FIN-02: a date or a clear event): the first agreement's approval,
 * the contract's or design's first approval, the start of a named flow stage (or of the stage holding the payment
 * check with the due's reference), the first or the completing confirmed delivery, or its date — plus the due's
 * offset days (ADR-0037). Dates are in the facility's time zone. Statuses are derived here and never stored; a due's
 * grace days only move when it counts as overdue.
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
        $contracts = self::firstApprovals($needs(DueEvent::ContractApproved), DocumentKind::Contract, $timezone);
        $designs = self::firstApprovals($needs(DueEvent::DesignApproved), DocumentKind::Design, $timezone);
        $started = self::stageStarts($needs(DueEvent::StageStarted), $timezone);
        $delivered = self::firstDeliveryDates($needs(DueEvent::Delivery), false);
        $completed = self::firstDeliveryDates($needs(DueEvent::DeliveryCompleted), true);
        $stages = self::paymentStageStarts($needs(DueEvent::PaymentStage), $timezone);

        $dates = [];
        foreach ($dues as $orderId => $orderDues) {
            foreach ($orderDues as $due) {
                $on = match ($due->due_event) {
                    DueEvent::Date => $due->due_date?->toDateString(),
                    DueEvent::Agreement => $agreed[$orderId] ?? null,
                    DueEvent::ContractApproved => $contracts[$orderId] ?? null,
                    DueEvent::DesignApproved => $designs[$orderId] ?? null,
                    DueEvent::StageStarted => $started[$orderId][(string) $due->stage_key] ?? null,
                    DueEvent::Delivery => $delivered[$orderId] ?? null,
                    DueEvent::DeliveryCompleted => $completed[$orderId] ?? null,
                    DueEvent::PaymentStage => $stages[$orderId][$due->reference] ?? null,
                };
                $dates[$due->id] = $on === null || $due->offset_days === 0 ? $on : CarbonImmutable::parse($on)->addDays($due->offset_days)->toDateString();
            }
        }

        return $dates;
    }

    /**
     * The due's state today. Inside its grace days a fallen-due due stays «due», not «overdue».
     */
    public static function status(int $amount, int $allocated, ?string $dueOn, ?string $today = null, int $graceDays = 0): DueStatus
    {
        $today ??= AppointmentBooking::today();
        if ($allocated >= $amount) {
            return DueStatus::Paid;
        }
        if ($dueOn === null || $dueOn > $today) {
            return $allocated > 0 ? DueStatus::PartiallyPaid : DueStatus::Upcoming;
        }
        if (self::overdueFrom($dueOn, $graceDays) <= $today) {
            return DueStatus::Overdue;
        }

        return $allocated > 0 ? DueStatus::PartiallyPaid : DueStatus::Due;
    }

    /**
     * The first day a due counts as overdue: the day after its due date and grace days.
     */
    public static function overdueFrom(string $dueOn, int $graceDays = 0): string
    {
        return CarbonImmutable::parse($dueOn)->addDays($graceDays + 1)->toDateString();
    }

    /**
     * The first approval day of a document kind per order (a later replacement never moves it).
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private static function firstApprovals(array $orderIds, DocumentKind $kind, string $timezone): array
    {
        if ($orderIds === []) {
            return [];
        }
        $dates = [];
        $documents = AgreementDocument::query()->whereIn('order_id', $orderIds)->where('kind', $kind->value)
            ->where('status', DocumentStatus::Approved->value)->whereNotNull('decided_at')->orderBy('decided_at')->get(['order_id', 'decided_at']);
        foreach ($documents as $document) {
            $dates[$document->order_id] ??= CarbonImmutable::parse($document->decided_at)->setTimezone($timezone)->toDateString();
        }

        return $dates;
    }

    /**
     * @param  list<int>  $orderIds
     * @return array<int, array<string, string>> order id → stage key → the day it started
     */
    private static function stageStarts(array $orderIds, string $timezone): array
    {
        if ($orderIds === []) {
            return [];
        }
        $starts = [];
        foreach (OrderStage::query()->whereIn('order_id', $orderIds)->whereNotNull('started_at')->get(['order_id', 'key', 'started_at']) as $stage) {
            $starts[$stage->order_id][$stage->key] = CarbonImmutable::parse($stage->started_at)->setTimezone($timezone)->toDateString();
        }

        return $starts;
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
     * The date of each order's first confirmed delivery note (P7, ADR-0018) — or, for `$completing`, of the note
     * that completed the delivery: later deliveries never move it, so an «at delivery» due falls due once.
     *
     * @param  list<int>  $orderIds
     * @return array<int, string>
     */
    private static function firstDeliveryDates(array $orderIds, bool $completing): array
    {
        if ($orderIds === []) {
            return [];
        }
        $dates = [];
        $notes = DeliveryNote::query()->whereIn('order_id', $orderIds)->where('status', DeliveryStatus::Confirmed->value)
            ->when($completing, fn ($q) => $q->where('completes_order', true))->orderBy('delivered_on')->orderBy('id')->get(['id', 'order_id', 'delivered_on']);
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
