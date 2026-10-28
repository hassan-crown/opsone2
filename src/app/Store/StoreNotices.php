<?php

namespace App\Store;

use App\Access\Permission;
use App\Models\Order;
use App\Models\Store\StoreCheckout;
use App\Notifications\NotificationCatalogue;
use App\Notifications\Notifier;

/**
 * Store notices to the member following the online order (its basket link's owner or the store handler), through the
 * notifier and its outbox — inside the change's transaction, deduplicated per checkout and event.
 */
final class StoreNotices
{
    public const PAID = 'store.order_paid';

    public const ATTENTION = 'store.needs_attention';

    public function __construct(private readonly Notifier $notifier) {}

    public static function define(): void
    {
        foreach ([self::PAID, self::ATTENTION] as $type) {
            $slug = str_replace('.', '_', $type);
            NotificationCatalogue::define($type, [
                'mandatory' => $type === self::ATTENTION,
                'permission' => Permission::P16,
                'title' => "notifications.type.{$slug}.title",
                'body' => "notifications.type.{$slug}.body",
                'route' => null,
            ]);
        }
    }

    public function paid(StoreCheckout $checkout, Order $order): void
    {
        $assignee = $order->assignee;
        if ($assignee !== null) {
            $this->notifier->send($assignee, self::PAID, ['order' => $order->number, 'checkout' => $checkout->number], $order, "store:{$checkout->id}:paid");
        }
    }

    public function attention(StoreCheckout $checkout, Order $order, string $reason): void
    {
        $assignee = $order->assignee;
        if ($assignee !== null) {
            $this->notifier->send($assignee, self::ATTENTION, [
                'order' => $order->number, 'checkout' => $checkout->number, 'reason' => __('checkout.attention.'.$reason),
            ], $order, "store:{$checkout->id}:attention:{$reason}");
        }
    }
}
