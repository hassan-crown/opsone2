<?php

namespace App\Orders;

use App\Enums\StageStatus;
use App\Models\Order;
use App\Models\OrderStage;
use App\Models\User;
use App\Reports\Definitions\SalesReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where an order stands in its flow, for the order list, the order header and the home «orders needing attention»
 * card: the active stage, how many days it is past its due date (OrderStage::daysLate) and the stage after it.
 * Ready orders and orders without a workflow have no stage (null). Lists read every stage in one query.
 */
final class OrderStageSummary
{
    /** Cap of open orders with a due date read for the home card (as the executive dashboard caps its stages). */
    public const ATTENTION_CAP = 2000;

    /**
     * The active stage of each order of a list, read in one query (keyed by order id; absent = none).
     *
     * @param  iterable<Order>  $orders
     * @return array<int, array{key: string, name: string, days_late: int}>
     */
    public static function forOrders(iterable $orders): array
    {
        $ids = [];
        foreach ($orders as $order) {
            $ids[] = $order->id;
        }
        if ($ids === []) {
            return [];
        }

        $today = now()->startOfDay();
        $out = [];
        foreach (self::activeStages()->whereIn('order_workflows.order_id', $ids)->get() as $stage) {
            $out[(int) $stage->getAttribute('workflow_order_id')] = [
                'key' => $stage->key,
                'name' => (string) $stage->name->get(),
                'days_late' => $stage->daysLate($today),
            ];
        }

        return $out;
    }

    /**
     * The order header: the active stage, its due date, its position and the next stage still to run.
     *
     * @return array{key: string, name: string, days_late: int, due_on: string|null, position: int, total: int, next: string|null}|null
     */
    public static function forOrder(Order $order): ?array
    {
        $order->loadMissing('workflow.activeStage');
        $active = $order->workflow?->activeStage;
        if ($active === null) {
            return null;
        }

        $stages = $order->stages()->get(['id', 'position', 'name', 'status'])->values();
        $next = $stages->first(fn (OrderStage $s) => $s->position > $active->position
            && ! in_array($s->status, [StageStatus::Completed, StageStatus::CompletedNeedsCorrection], true));
        $index = $stages->search(fn (OrderStage $s) => $s->id === $active->id);

        return [
            'key' => $active->key,
            'name' => (string) $active->name->get(),
            'days_late' => $active->daysLate(),
            'due_on' => $active->dueOn()?->toDateString(),
            'position' => is_int($index) ? $index + 1 : $active->position,
            'total' => $stages->count(),
            'next' => $next === null ? null : (string) $next->name->get(),
        ];
    }

    /**
     * Open orders the user may see whose active stage is past its due date, most late first.
     *
     * @return array{count: int, capped: bool, items: list<array{id: int, number: string, customer: string, stage: string, days_late: int}>}
     */
    public static function attention(User $user, int $take = 3): array
    {
        $today = now()->startOfDay();
        $visible = OrderAccess::visible($user)->whereNotIn('orders.status', SalesReport::TERMINAL)->select('orders.id');
        // One query: in-progress active stages with a due date, with their order's number and customer.
        $stages = self::activeStages()
            ->join('orders', 'orders.id', '=', 'order_workflows.order_id')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('order_workflows.order_id', $visible)
            ->where('order_stages.status', StageStatus::InProgress->value)
            ->where(fn ($d) => $d->whereNotNull('order_stages.expected_on')
                ->orWhere(fn ($t) => $t->whereNotNull('order_stages.target_days')->whereNotNull('order_stages.started_at')))
            ->addSelect(['orders.number as order_number', 'customers.name as customer_name'])
            ->orderBy('order_workflows.order_id')
            ->limit(self::ATTENTION_CAP + 1)
            ->get();

        $late = [];
        foreach ($stages->take(self::ATTENTION_CAP) as $stage) {
            $days = $stage->daysLate($today);
            if ($days > 0) {
                $late[] = [
                    'id' => (int) $stage->getAttribute('workflow_order_id'),
                    'number' => (string) $stage->getAttribute('order_number'),
                    'customer' => (string) $stage->getAttribute('customer_name'),
                    'stage' => (string) $stage->name->get(),
                    'days_late' => $days,
                ];
            }
        }
        usort($late, fn (array $a, array $b) => $b['days_late'] <=> $a['days_late']);

        return [
            'count' => count($late),
            'capped' => $stages->count() > self::ATTENTION_CAP,
            'items' => array_slice($late, 0, $take),
        ];
    }

    /**
     * Active stages joined to their workflow; each row carries `workflow_order_id`.
     *
     * @return Builder<OrderStage>
     */
    private static function activeStages(): Builder
    {
        return OrderStage::query()
            ->join('order_workflows', 'order_workflows.active_stage_id', '=', 'order_stages.id')
            ->select(['order_stages.*', 'order_workflows.order_id as workflow_order_id']);
    }
}
