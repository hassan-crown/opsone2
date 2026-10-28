<?php

namespace App\Finance;

use App\Agreements\OrderAgreement;
use App\Enums\DeliveryStatus;
use App\Enums\DueEvent;
use App\Enums\DueStatus;
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
        $timezone = AppointmentBooking::timezone();
        $agreedAt = OrderAgreement::history($order)->first()?->approved_at;
        $stageStarts = null;
        $firstDelivery = null;

        $dates = [];
        foreach ($dues as $due) {
            $dates[$due->id] = match ($due->due_event) {
                DueEvent::Date => $due->due_date?->toDateString(),
                DueEvent::Agreement => $agreedAt === null ? null : CarbonImmutable::parse($agreedAt)->setTimezone($timezone)->toDateString(),
                DueEvent::Delivery => $firstDelivery ??= self::firstDeliveryDate($order),
                DueEvent::PaymentStage => ($stageStarts ??= self::paymentStageStarts($order))[$due->reference] ?? null,
            };
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
     * The date of the order's first confirmed delivery note (P7, ADR-0018): later deliveries never move it, so an
     * «at delivery» due falls due once.
     */
    private static function firstDeliveryDate(Order $order): ?string
    {
        $note = DeliveryNote::query()->where('order_id', $order->id)->where('status', DeliveryStatus::Confirmed->value)
            ->orderBy('delivered_on')->orderBy('id')->first();

        return $note?->delivered_on?->toDateString();
    }

    /**
     * @return array<string, string> payment check reference → the date its stage started
     */
    private static function paymentStageStarts(Order $order): array
    {
        $timezone = AppointmentBooking::timezone();
        $starts = [];
        $actions = OrderAction::query()->with('stage')->where('order_id', $order->id)->where('type', ActionType::PaymentCheck->value)->get();
        foreach ($actions as $action) {
            $reference = (string) ($action->inputs['reference'] ?? '');
            $started = $action->stage->started_at;
            if ($reference !== '' && $started !== null) {
                $starts[$reference] = CarbonImmutable::parse($started)->setTimezone($timezone)->toDateString();
            }
        }

        return $starts;
    }
}
