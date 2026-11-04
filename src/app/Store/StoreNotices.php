<?php

namespace App\Store;

use App\Access\Permission;
use App\Models\Facility;
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

    /** The store-wide checkout code cap is near (M-4): to the owner, mandatory. */
    public const OTP_CAP = 'store.otp_cap_near';

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
        NotificationCatalogue::define(self::OTP_CAP, [
            'mandatory' => true,
            'permission' => null,
            'title' => 'notifications.type.store_otp_cap_near.title',
            'body' => 'notifications.type.store_otp_cap_near.body',
            'route' => null,
        ]);
    }

    /**
     * Once per cap window: the checkout codes sent this hour or day reached the warning share of the store-wide cap.
     */
    public function otpCapNear(string $period, int $max): void
    {
        $owner = Facility::current()?->owner?->user;
        if ($owner !== null) {
            $window = $period === 'hour' ? now()->format('YmdH') : now()->format('Ymd');
            $this->notifier->send($owner, self::OTP_CAP, ['max' => $max, 'period' => __("checkout.otp.cap_period.{$period}")], null, "store-otp-cap:{$period}:{$window}");
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
