<?php

namespace App\Whatsapp;

/**
 * The customer events that may go out on WhatsApp (ADR-0030). Each maps to one approved template name and an
 * ordered list of body parameters; `{{1}}…` at Meta correspond to `params()` in this order.
 */
enum WhatsAppEvent: string
{
    case MeasurementBooked = 'measurement_booked';
    case MeasurementReminder = 'measurement_reminder';
    case DesignReady = 'design_ready';
    case QuoteReady = 'quote_ready';
    case PaymentDue = 'payment_due';
    case ReceiptApproved = 'receipt_approved';
    case InstallationScheduled = 'installation_scheduled';
    case InstallationReminder = 'installation_reminder';
    case OrderCompleted = 'order_completed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    public function template(): string
    {
        return (string) config('whatsapp.templates.'.$this->value, config('whatsapp.template_prefix').$this->value);
    }

    /**
     * Parameter names in template order.
     *
     * @return list<string>
     */
    public function params(): array
    {
        return match ($this) {
            self::MeasurementBooked, self::MeasurementReminder, self::InstallationScheduled, self::InstallationReminder => ['name', 'order', 'when', 'facility'],
            self::DesignReady, self::QuoteReady => ['name', 'order', 'link', 'facility'],
            self::PaymentDue => ['name', 'order', 'amount', 'due', 'link'],
            self::ReceiptApproved => ['name', 'order', 'amount', 'number'],
            self::OrderCompleted => ['name', 'order', 'link', 'facility'],
        };
    }

    /** Translation key of the rendered text (also the SMS fallback and the staff log). */
    public function textKey(): string
    {
        return 'whatsapp.event.'.$this->value;
    }
}
