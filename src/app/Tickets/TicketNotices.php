<?php

namespace App\Tickets;

use App\Access\Permission;
use App\Models\Facility;
use App\Models\Tickets\Ticket;
use App\Models\User;
use App\Notifications\NotificationCatalogue;
use App\Notifications\Notifier;
use App\Notifications\Outbox;

/**
 * Messages of the ticket flow, queued in the caller's transaction (outbox, ADR-0007). To the customer: SMS + mail with
 * NO body, no internal note and no file — only that there is an update; the content stays in the account, so a failed
 * channel never loses it and a retry never repeats it. To staff: an in-app notice whose link re-checks P-59.
 */
final class TicketNotices
{
    public const OPENED = 'ticket.opened';

    public const CUSTOMER_REPLIED = 'ticket.customer_replied';

    public const ASSIGNED = 'ticket.assigned';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly Notifier $notifier,
    ) {}

    public static function define(): void
    {
        foreach ([self::OPENED, self::CUSTOMER_REPLIED, self::ASSIGNED] as $type) {
            $slug = str_replace('.', '_', $type);
            NotificationCatalogue::define($type, [
                'mandatory' => false,
                'permission' => Permission::P59,
                'title' => "tickets.notify.{$slug}.title",
                'body' => "tickets.notify.{$slug}.body",
                'route' => null,
            ]);
        }
    }

    public function staff(?User $to, ?User $actor, string $type, Ticket $ticket, string $dedupe): void
    {
        if ($to === null || $to->id === $actor?->id) {
            return;
        }
        $this->notifier->send($to, $type, ['number' => $ticket->number, 'subject' => $ticket->subject], $ticket, $dedupe);
    }

    /**
     * @param  string  $event  reply | taken | info | resolved | closed | reopened
     */
    public function customer(Ticket $ticket, string $event, string $dedupe): void
    {
        $customer = $ticket->customer;
        $facility = Facility::current();
        $locale = $customer->portal_locale ?? ($facility === null ? 'ar' : $facility->default_locale);
        $text = __("tickets.message.{$event}", ['number' => $ticket->number], $locale);

        $this->outbox->enqueueSms($customer->mobile, $text, "{$dedupe}:sms");
        if ($customer->email !== null && $customer->email !== '') {
            $name = $facility === null ? '' : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
            $this->outbox->enqueueMail($customer->email, __('tickets.message.subject', ['facility' => $name], $locale), [$text], null, $locale, "{$dedupe}:mail");
        }
    }
}
