<?php

namespace App\Ai\AutoReply;

use App\Enums\AppointmentStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Inbox\Conversation;
use App\Models\Order;
use App\Models\WorkSettings;
use App\Money\Money;
use App\Portal\PortalAccess;
use App\Portal\PortalPresenter;
use App\Workflow\AppointmentBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Builds the text of an automatic reply (AI-6): a FIXED template from lang/*.json (`ai.autoreply.reply.*`) whose
 * parameters come only from the database. Nothing the model wrote is used here — only the intent it picked.
 *
 * Personal answers (order stage, next appointment, amount due with the payment link) need the sender to BE the
 * customer: the conversation's customer must have exactly this WhatsApp number, and no other customer may share it.
 * Only that customer's own orders are read (as their portal reads them). With several open orders the reply asks
 * for the order number, listing only their own numbers; a number in the message picks one of THEIR orders only.
 */
final class AutoReplyAnswers
{
    /** Reasons for handing off instead of answering. */
    public const UNKNOWN_CUSTOMER = 'unknown_customer';

    public const NO_OPEN_ORDER = 'no_open_order';

    public const NO_FACT = 'no_fact';

    /** Recorded on a sent reply that asked which order (the next message may name it). */
    public const ASKED_ORDER = 'asked_order';

    /**
     * @return array{text: string, order_id: int|null, reason: string|null}|array{handoff: string}
     */
    public function answer(AutoReplyIntent $intent, Conversation $conversation, string $body): array
    {
        $locale = self::locale();

        if (! $intent->personal()) {
            return match ($intent) {
                AutoReplyIntent::Location => $this->location($locale),
                default => $this->workingHours($locale),
            };
        }

        $customer = self::matchedCustomer($conversation);
        if ($customer === null) {
            return ['handoff' => self::UNKNOWN_CUSTOMER];
        }

        $orders = self::openOrders($customer);
        if ($orders->isEmpty()) {
            return ['handoff' => self::NO_OPEN_ORDER];
        }
        $order = $orders->count() === 1 ? $orders->first() : self::named($orders, $body);
        if ($order === null) {
            return [
                'text' => __('ai.autoreply.reply.which_order', ['numbers' => $orders->pluck('number')->implode($locale === 'ar' ? '، ' : ', ')], $locale),
                'order_id' => null,
                'reason' => self::ASKED_ORDER,
            ];
        }

        return match ($intent) {
            AutoReplyIntent::OrderStatus => $this->orderStatus($order, $locale),
            AutoReplyIntent::Appointment => $this->appointment($order, $locale),
            default => $this->paymentDue($order, $locale),
        };
    }

    /**
     * The customer this conversation's number belongs to, or null when it cannot be proven: not linked, linked to a
     * customer with another number (a manual link), or a number shared by several customers.
     */
    public static function matchedCustomer(Conversation $conversation): ?Customer
    {
        $customer = $conversation->customer;
        if ($customer === null || $customer->mobile === null || $customer->mobile !== $conversation->wa_id) {
            return null;
        }

        return Customer::query()->where('mobile', $conversation->wa_id)->count() === 1 ? $customer : null;
    }

    /**
     * @return Collection<int, Order>
     */
    public static function openOrders(Customer $customer): Collection
    {
        $closed = [CustomOrderStatus::Cancelled->value, CustomOrderStatus::Closed->value, ReadyOrderStatus::Delivered->value];

        return PortalAccess::orders($customer)->whereNotIn('orders.status', $closed)->orderByDesc('orders.id')->limit(10)->get();
    }

    /**
     * One of the customer's own orders whose number appears in the message (Arabic digits accepted).
     *
     * @param  Collection<int, Order>  $orders
     */
    public static function named(Collection $orders, string $body): ?Order
    {
        $text = mb_strtoupper(strtr($body, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
        $found = $orders->filter(fn (Order $o) => $o->number !== null && $o->number !== '' && str_contains($text, mb_strtoupper($o->number)));

        return $found->count() === 1 ? $found->first() : null;
    }

    /**
     * @return array{text: string, order_id: int, reason: null}|array{handoff: string}
     */
    private function orderStatus(Order $order, string $locale): array
    {
        $stage = PortalPresenter::orders([$order])[0]['stage'] ?? null;
        if (! is_string($stage) || trim($stage) === '') {
            return ['handoff' => self::NO_FACT];
        }

        return ['text' => __('ai.autoreply.reply.order_status', ['number' => $order->number, 'stage' => $stage], $locale), 'order_id' => $order->id, 'reason' => null];
    }

    /**
     * @return array{text: string, order_id: int, reason: null}|array{handoff: string}
     */
    private function appointment(Order $order, string $locale): array
    {
        $next = Appointment::query()->where('order_id', $order->id)
            ->whereIn('status', [AppointmentStatus::AwaitingConfirmation->value, AppointmentStatus::Confirmed->value])
            ->where('starts_at', '>', now())->orderBy('starts_at')->first();
        if ($next === null) {
            return ['handoff' => self::NO_FACT];
        }
        $at = CarbonImmutable::parse($next->starts_at)->setTimezone(AppointmentBooking::timezone());

        return ['text' => __('ai.autoreply.reply.appointment', [
            'kind' => __('appointments.kind.'.$next->kind, [], $locale),
            'number' => $order->number,
            'day' => __('appointments.work.day.'.$at->isoWeekday(), [], $locale),
            'date' => $at->toDateString(),
            'time' => $at->format('H:i'),
            'status' => __('ai.autoreply.appointment_status.'.$next->status->value, [], $locale),
        ], $locale), 'order_id' => $order->id, 'reason' => null];
    }

    /**
     * @return array{text: string, order_id: int, reason: null}|array{handoff: string}
     */
    private function paymentDue(Order $order, string $locale): array
    {
        $due = (int) PortalPresenter::finance($order)['due_now_minor'];
        if ($due <= 0) {
            return ['handoff' => self::NO_FACT];
        }

        return ['text' => __('ai.autoreply.reply.payment_due', [
            'number' => $order->number,
            'amount' => Money::of($due)->toInput(),
            'link' => URL::route('customer.orders.pay', ['order' => $order->id]),
        ], $locale), 'order_id' => $order->id, 'reason' => null];
    }

    /**
     * @return array{text: string, order_id: null, reason: null}|array{handoff: string}
     */
    private function location(string $locale): array
    {
        $facility = Facility::current();
        $address = trim((string) $facility?->address);
        if ($facility === null || $address === '') {
            return ['handoff' => self::NO_FACT];
        }

        return ['text' => __('ai.autoreply.reply.location', ['facility' => self::facilityName($facility, $locale), 'address' => $address], $locale), 'order_id' => null, 'reason' => null];
    }

    /**
     * @return array{text: string, order_id: null, reason: null}
     */
    private function workingHours(string $locale): array
    {
        $settings = WorkSettings::current();
        $order = [7, 1, 2, 3, 4, 5, 6];
        $days = array_values(array_filter($order, fn (int $d) => in_array($d, $settings->working_days, true)));

        return ['text' => __('ai.autoreply.reply.working_hours', [
            'days' => implode($locale === 'ar' ? '، ' : ', ', array_map(fn (int $d) => __('appointments.work.day.'.$d, [], $locale), $days)),
            'from' => $settings->work_starts_at,
            'to' => $settings->work_ends_at,
        ], $locale), 'order_id' => null, 'reason' => null];
    }

    public static function locale(): string
    {
        $locale = Facility::current()?->default_locale;

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    private static function facilityName(Facility $facility, string $locale): string
    {
        return $locale === 'en' && trim((string) $facility->name_en) !== '' ? (string) $facility->name_en : $facility->name_ar;
    }
}
