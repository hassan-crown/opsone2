<?php

namespace App\Inventory;

use App\Access\Access;
use App\Access\Permission;
use App\Enums\MembershipStatus;
use App\Models\Inventory\InventorySettings;
use App\Models\Inventory\StockBalance;
use App\Models\Inventory\StockItem;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Optional reorder level (FUL «حد إعادة الطلب»): when the item's available quantity across active locations falls
 * below it, holders of P-49 are told once a day in-app (and by email per their preference). It never buys anything.
 * Runs after commit, so a rolled-back movement never alerts.
 */
final class LowStockAlerts
{
    public function __construct(private readonly Notifier $notifier) {}

    public function check(StockItem $item): void
    {
        if ($item->reorder_level_milli === null) {
            return;
        }
        $itemId = $item->id;
        DB::afterCommit(fn () => $this->notify($itemId));
    }

    public static function availableMilli(int $stockItemId): int
    {
        return (int) StockBalance::query()->where('stock_item_id', $stockItemId)
            ->whereIn('location_id', fn ($q) => $q->select('id')->from('stock_locations')->where('status', 'active'))
            ->sum(DB::raw('on_hand_milli - reserved_milli'));
    }

    public function notify(int $stockItemId): void
    {
        $item = StockItem::query()->find($stockItemId);
        if ($item === null || $item->reorder_level_milli === null || ! InventorySettings::current()->low_stock_alerts) {
            return;
        }
        $available = self::availableMilli($item->id);
        if ($available >= $item->reorder_level_milli) {
            return;
        }
        $described = StockItems::describe($item);
        $userIds = Membership::query()->where('status', MembershipStatus::Active)->pluck('user_id');
        foreach (User::query()->whereIn('id', $userIds)->where('is_support', false)->get() as $user) {
            if (! Access::can($user, Permission::P49)) {
                continue;
            }
            $this->notifier->send($user, 'stock.low', [
                'item' => $described['name'],
                'available' => Quantity::format($available),
                'level' => Quantity::format($item->reorder_level_milli),
            ], null, 'stock-low:'.$item->id.':'.now()->toDateString());
        }
    }
}
