<?php

namespace App\Procurement;

use App\Models\Order;
use App\Models\OrderAction;
use App\Models\OrderStage;
use App\Models\User;
use App\Notifications\Notifier;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Support\Facades\DB;

/**
 * «Late at the workshop» (ADR-0038, W12): once a day, every open workshop purchase order past its expected date
 * notifies its responsible person and, for each linked order, the responsible of the stage that waits for it and the
 * order's owner. Once per purchase order, person and expected date: moving the date re-arms it.
 */
final class WorkshopLateAlerts
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        $late = WorkshopOrders::openQuery()
            ->whereNotNull('expected_on')->where('expected_on', '<', $today->toDateString())
            ->with(['lines', 'supplier'])->orderBy('id')->get();

        foreach ($late as $po) {
            $days = WorkshopOrders::lateDays($po, $today);
            $people = [$po->responsible_id];
            foreach ($po->lines->pluck('order_id')->filter()->unique() as $orderId) {
                $order = Order::query()->find((int) $orderId);
                $stageIds = OrderAction::query()->where('order_id', $orderId)->where('type', ActionType::WorkshopOrder->value)->pluck('order_stage_id');
                $people[] = $order?->assignee_id;
                foreach (OrderStage::query()->whereIn('id', $stageIds->all())->whereNull('completed_at')->pluck('follow_up_user_id') as $userId) {
                    $people[] = $userId;
                }
            }

            foreach (array_unique(array_filter($people)) as $userId) {
                $user = User::query()->find((int) $userId);
                if ($user === null || $user->is_support) {
                    continue;
                }
                DB::transaction(function () use ($user, $po, $days, &$sent) {
                    $created = $this->notifier->send($user, 'workshop.late', [
                        'number' => $po->number, 'supplier' => (string) $po->supplier->name->get(), 'days' => $days,
                    ], $po, 'workshop.late:'.$po->id.':'.$po->expected_on?->toDateString());
                    $sent += $created === null ? 0 : 1;
                });
            }
        }

        return $sent;
    }
}
