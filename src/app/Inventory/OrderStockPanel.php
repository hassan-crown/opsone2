<?php

namespace App\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\ReadyOrderStatus;
use App\Models\Inventory\StockLocation;
use App\Models\Order;
use App\Models\User;

/**
 * The stock panel of the order page (P7a): for a ready order the per-line availability before confirmation and the
 * reservations after it, with confirm (P-15) and cancel-the-rest (P-18); for a custom order a link to its materials
 * screen (P-51). Availability is not sensitive: whoever can open the order and sell sees it (P-48 matrix note).
 */
final class OrderStockPanel
{
    /**
     * @return array<string, mixed>
     */
    public static function forOrder(Order $order, User $actor): array
    {
        if (! $order->isReady()) {
            $reserved = OrderStock::reservations($order)->filter(fn ($r) => $r->status->isOpen())->count();

            return [
                'kind' => 'custom',
                'open_reservations' => $reserved,
                'can' => ['materials' => Access::can($actor, Permission::P51, $order) || Access::can($actor, Permission::P48)],
            ];
        }

        $state = $order->state();

        return [
            'kind' => 'ready',
            'lines' => OrderStock::readyLines($order),
            'locations' => $state === ReadyOrderStatus::Draft
                ? OrderStock::locationsFor($order)->map(fn (StockLocation $l) => ['id' => $l->id, 'code' => $l->code, 'name' => (string) $l->name->get(app()->getLocale())])->values()->all()
                : [],
            'can' => [
                'confirm' => Access::can($actor, Permission::P15, $order),
                'cancel' => in_array($state, [ReadyOrderStatus::Draft, ReadyOrderStatus::Confirmed, ReadyOrderStatus::PartiallyDelivered], true)
                    && Access::can($actor, Permission::P18, $order),
            ],
        ];
    }
}
