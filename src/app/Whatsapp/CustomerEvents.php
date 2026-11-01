<?php

namespace App\Whatsapp;

use App\Enums\AppointmentStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\ReadyOrderStatus;
use App\Models\Agreements\AgreementDocument;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Facility;
use App\Models\Order;
use App\Models\WorkSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Turns domain changes into customer notifications (ADR-0030): measurement/installation booked, quote or design ready,
 * order completed (with the link to leave feedback in the customer's workspace) and, from the scheduler, the 24-hour
 * reminders. Reacting to the saved models keeps the workflow actions untouched; a failure here is reported and never
 * undoes the business change. The messages themselves are idempotent (see CustomerMessenger).
 */
final class CustomerEvents
{
    /** Appointment kinds that notify the customer, with their «booked» and «reminder» events. */
    private const APPOINTMENTS = [
        'measurement' => [WhatsAppEvent::MeasurementBooked, WhatsAppEvent::MeasurementReminder],
        'installation' => [WhatsAppEvent::InstallationScheduled, WhatsAppEvent::InstallationReminder],
    ];

    public const REMINDER_HOURS = 24;

    public static function appointmentSaved(Appointment $appointment): void
    {
        if (! isset(self::APPOINTMENTS[$appointment->kind])
            || ! in_array($appointment->status, [AppointmentStatus::AwaitingConfirmation, AppointmentStatus::Confirmed], true)
            || ! $appointment->wasChanged(['status', 'starts_at'])
            || $appointment->starts_at->isPast()) {
            return;
        }

        self::guarded(fn () => self::appointment($appointment, self::APPOINTMENTS[$appointment->kind][0], 'booked'));
    }

    public static function documentSaved(AgreementDocument $document): void
    {
        if ($document->status !== DocumentStatus::AwaitingCustomer || ! $document->wasChanged('status')) {
            return;
        }

        $event = match ($document->kind) {
            DocumentKind::Quote => WhatsAppEvent::QuoteReady,
            DocumentKind::Design => WhatsAppEvent::DesignReady,
            DocumentKind::Contract => null,
        };
        if ($event === null) {
            return;
        }

        self::guarded(function () use ($document, $event) {
            $order = $document->order;
            // A re-sent document is a new send: the send time keeps each one a distinct, idempotent message.
            self::messenger()->send(
                $order->customer,
                $event,
                ['order' => $order->number, 'link' => self::link('customer.documents.show', ['document' => $document->id])],
                "document:{$document->id}:{$document->sent_at->timestamp}:ready",
                $order,
            );
        });
    }

    public static function orderSaved(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }
        $done = $order->isReady()
            ? $order->status === ReadyOrderStatus::Delivered->value
            : $order->status === CustomOrderStatus::ExecutionFinished->value;
        if (! $done) {
            return;
        }

        self::guarded(fn () => self::messenger()->send(
            $order->customer,
            WhatsAppEvent::OrderCompleted,
            ['order' => $order->number, 'link' => self::link('customer.orders.show', ['order' => $order->id])],
            "order:{$order->id}:completed",
            $order,
        ));
    }

    /**
     * The scheduler's run: 24-hour reminders for confirmed measurements and installations, only inside the
     * facility's reminder hours. Returns how many were queued.
     */
    public static function remindDue(CarbonInterface $now): int
    {
        if (! WorkSettings::current()->canSendReminderAt($now)) {
            return 0;
        }

        $queued = 0;
        $due = Appointment::query()
            ->whereIn('kind', array_keys(self::APPOINTMENTS))
            ->where('status', AppointmentStatus::Confirmed->value)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->copy()->addHours(self::REMINDER_HOURS))
            ->with('order.customer')
            ->get();

        foreach ($due as $appointment) {
            $queued += (int) (self::appointment($appointment, self::APPOINTMENTS[$appointment->kind][1], 'reminder') !== null);
        }

        return $queued;
    }

    private static function appointment(Appointment $appointment, WhatsAppEvent $event, string $suffix): ?CustomerMessage
    {
        $order = $appointment->order;
        $customer = $order->customer;

        // The start time is in the key: a rescheduled appointment is a new message, the same one is never repeated.
        return self::messenger()->send(
            $customer,
            $event,
            ['order' => $order->number, 'when' => self::when($appointment, $customer)],
            "appointment:{$appointment->id}:{$appointment->starts_at->timestamp}:{$suffix}",
            $order,
        );
    }

    private static function when(Appointment $appointment, Customer $customer): string
    {
        return $appointment->starts_at->copy()
            ->setTimezone(Facility::current()?->timezone ?: config('app.timezone'))
            ->locale(CustomerMessenger::locale($customer))
            ->translatedFormat('Y-m-d H:i');
    }

    /**
     * @param  array<string, int>  $parameters
     */
    private static function link(string $route, array $parameters): string
    {
        return URL::route($route, $parameters);
    }

    private static function messenger(): CustomerMessenger
    {
        return app(CustomerMessenger::class);
    }

    private static function guarded(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
