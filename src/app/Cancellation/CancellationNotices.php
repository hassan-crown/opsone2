<?php

namespace App\Cancellation;

use App\Access\Permission;
use App\Models\Cancellation\OrderCancellation;
use App\Models\Customer;
use App\Models\Facility;
use App\Models\Order;
use App\Models\User;
use App\Notifications\NotificationCatalogue;
use App\Notifications\Notifier;
use App\Notifications\Outbox;

/**
 * Messages of a cancellation decision, queued in the same transaction (outbox, ADR-0007): the customer is told the
 * decision (SMS + mail, no amounts — the settlement is followed separately), the order's assignee gets an in-app
 * notice whose link re-checks P-16 on the request.
 */
final class CancellationNotices
{
    public const ACCEPTED = 'cancellation.accepted';

    public const REJECTED = 'cancellation.rejected';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly Notifier $notifier,
    ) {}

    public static function define(): void
    {
        foreach ([self::ACCEPTED, self::REJECTED] as $type) {
            $slug = str_replace('.', '_', $type);
            NotificationCatalogue::define($type, [
                'mandatory' => false,
                'permission' => Permission::P16,
                'title' => "notifications.type.{$slug}.title",
                'body' => "notifications.type.{$slug}.body",
                'route' => null,
            ]);
        }
    }

    public function accepted(OrderCancellation $cancellation, Order $order, ?User $actor): void
    {
        $key = $cancellation->isFull() ? 'cancel.message.accepted_full' : 'cancel.message.accepted_partial';
        $this->toCustomer($order->customer, $key, ['order' => $order->number, 'number' => $cancellation->number], "cancellation:{$cancellation->id}:accepted");
        $this->toAssignee($order, $actor, self::ACCEPTED, $cancellation);
    }

    public function rejected(OrderCancellation $cancellation, Order $order, ?User $actor): void
    {
        $this->toCustomer($order->customer, 'cancel.message.rejected', [
            'order' => $order->number, 'reason' => (string) $cancellation->decision_reason,
        ], "cancellation:{$cancellation->id}:rejected");
        $this->toAssignee($order, $actor, self::REJECTED, $cancellation);
    }

    private function toAssignee(Order $order, ?User $actor, string $type, OrderCancellation $cancellation): void
    {
        $assignee = $order->assignee;
        if ($assignee !== null && $assignee->id !== $actor?->id) {
            $this->notifier->send($assignee, $type, ['order' => $order->number, 'number' => $cancellation->number], $cancellation, "cancellation:{$cancellation->id}:{$type}");
        }
    }

    /**
     * @param  array<string, string>  $params
     */
    private function toCustomer(Customer $customer, string $key, array $params, string $idempotency): void
    {
        $facility = Facility::current();
        $locale = $facility === null ? 'ar' : $facility->default_locale;
        $text = __($key, $params, $locale);
        $this->outbox->enqueueSms($customer->mobile, $text, "{$idempotency}:sms");
        if ($customer->email !== null && $customer->email !== '') {
            $name = $facility === null ? '' : ($locale === 'en' && $facility->name_en !== null ? $facility->name_en : $facility->name_ar);
            $this->outbox->enqueueMail($customer->email, __('cancel.message.subject', ['facility' => $name], $locale), [$text], null, $locale, "{$idempotency}:mail");
        }
    }
}
