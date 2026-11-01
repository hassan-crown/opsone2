<?php

namespace App\Whatsapp;

use App\Enums\ContactChannel;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Facility;
use App\Models\Order;
use App\Notifications\Channels\WhatsApp\WhatsAppChannel;
use App\Notifications\Outbox;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The one door for customer event notifications (ADR-0029). It decides the channel — WhatsApp when the facility
 * enabled the channel and the event, the driver can send, the customer has not opted out and does not prefer another
 * channel; SMS otherwise — records the message in the customer's log and queues it through the transactional outbox
 * (so it commits with the business change). A repeated call with the same key is a no-op. Call it inside the
 * caller's transaction.
 */
final class CustomerMessenger
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppChannel $channel,
    ) {}

    /**
     * @param  array<string, scalar|null>  $params  non-sensitive values named after `WhatsAppEvent::params()`
     * @param  string|null  $smsText  the text for SMS (and the log) when the caller has its own wording
     * @return CustomerMessage|null null when nothing new was queued (repeat, or no number to reach)
     */
    public function send(Customer $customer, WhatsAppEvent $event, array $params, string $key, ?Order $order = null, ?string $smsText = null): ?CustomerMessage
    {
        if (trim($customer->mobile) === '') {
            return null;
        }

        $locale = self::locale($customer);
        $params = ['name' => $customer->name, 'facility' => self::facilityName($locale), ...$params];
        $body = $smsText ?? __($event->textKey(), $params, $locale);
        $whatsapp = $this->usesWhatsApp($customer, $event);

        try {
            $message = CustomerMessage::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'customer_id' => $customer->id,
                    'order_id' => $order?->id,
                    'event' => $event,
                    'channel' => $whatsapp ? 'whatsapp' : 'sms',
                    'template' => $whatsapp ? $event->template() : null,
                    'locale' => $locale,
                    'status' => MessageStatus::Queued,
                    'body' => $body,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if (! $message->wasRecentlyCreated) {
            return null;
        }

        if ($whatsapp) {
            $this->outbox->enqueueWhatsApp($customer->mobile, [
                'template' => $event->template(),
                'language' => $locale,
                'parameters' => array_map(fn (string $name) => (string) ($params[$name] ?? ''), $event->params()),
                'sms_text' => $body,
                'customer_message_id' => $message->id,
            ], "{$key}:wa");
        } else {
            $this->outbox->enqueueSms($customer->mobile, $body, "{$key}:sms", $message->id);
        }

        return $message;
    }

    public function usesWhatsApp(Customer $customer, WhatsAppEvent $event): bool
    {
        return $this->settings->allows($event)
            && $this->channel->isConfigured()
            && self::consents($customer);
    }

    /** Consent: not opted out, and WhatsApp is the customer's preferred channel or they have no preference. */
    public static function consents(Customer $customer): bool
    {
        return $customer->whatsapp_opt_out_at === null
            && ($customer->preferred_channel === null || $customer->preferred_channel === ContactChannel::Whatsapp);
    }

    public static function locale(Customer $customer): string
    {
        $facility = Facility::current();
        $locale = $customer->portal_locale ?? ($facility === null ? 'ar' : $facility->default_locale);

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    public static function facilityName(string $locale): string
    {
        $facility = Facility::current();
        if ($facility === null) {
            return (string) config('app.name');
        }

        return $locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar;
    }
}
