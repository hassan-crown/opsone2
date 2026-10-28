<?php

namespace App\Finance;

use App\Agreements\OrderAgreement;
use App\Commissions\CommissionAccrual;
use App\Enums\ActionStatus;
use App\Models\Order;
use App\Models\OrderAction;
use App\Models\User;
use App\Money\Money;
use App\Workflow\ActionResults;
use App\Workflow\Catalogue\ActionType;
use Illuminate\Support\Collection;

/**
 * The stage collection condition (SRS-INT-07, P5 `payment_check`), read from the ledger: the order's net collected is
 * compared with each check's threshold — `amount` (fixed), `percent` (of the agreed value, `OrderAgreement::share`),
 * `remaining` (the whole agreed value). The collection is never consumed, so «50% then 100%» compare the same
 * cumulative amount and never create two receipts. A met check is reported to the engine through
 * `ActionResults::verifyFromSource()` (`order_collection:{payment}`); a proof under review is not money and never
 * meets it (FIN-02). A later reversal never un-completes a stage: `shortfalls()` lists what is no longer covered.
 */
final class CollectionConditions
{
    public const SOURCE_TYPE = 'order_collection';

    public function __construct(
        private readonly ActionResults $results,
        private readonly CommissionAccrual $commissions,
    ) {}

    /**
     * Reports every newly met check and re-derives the sale's commission (6d, INT-06). Call inside the money
     * write's transaction (after the ledger changed).
     *
     * @return list<string> references reported now
     */
    public function evaluate(Order $order, int $paymentId, ?User $actor): array
    {
        $collected = MoneyLedger::collected($order->id);
        $reported = [];
        foreach (self::checks($order) as $action) {
            if ($action->source_reference !== null || ! in_array($action->status, [ActionStatus::Unavailable, ActionStatus::Ready, ActionStatus::AwaitingResult, ActionStatus::Allowed], true)) {
                continue;
            }
            $threshold = self::threshold($order, $action);
            if ($threshold === null || $collected->compare($threshold) < 0) {
                continue;
            }
            $reference = (string) ($action->inputs['reference'] ?? '');
            $this->results->verifyFromSource($order, ActionType::PaymentCheck, $reference, self::SOURCE_TYPE, $paymentId, $actor);
            $reported[] = $reference;
        }
        $this->commissions->recompute($order, self::SOURCE_TYPE.':'.$paymentId, $actor);

        return $reported;
    }

    /**
     * What each check needs, and whether it is met now. `threshold_minor` is null while it cannot be known (a
     * percentage or the rest before any agreement).
     *
     * @return list<array{reference: string, name: string|null, basis: string, threshold_minor: int|null, met: bool, verified: bool}>
     */
    public static function overview(Order $order): array
    {
        $collected = MoneyLedger::collected($order->id);

        return array_values(self::checks($order)->map(function (OrderAction $action) use ($order, $collected) {
            $threshold = self::threshold($order, $action);

            return [
                'reference' => (string) ($action->inputs['reference'] ?? ''),
                'name' => $action->name->get(),
                'basis' => (string) ($action->inputs['basis'] ?? ''),
                'threshold_minor' => $threshold?->minor,
                'met' => $threshold !== null && $collected->compare($threshold) >= 0,
                'verified' => $action->status === ActionStatus::Verified,
            ];
        })->all());
    }

    /**
     * Checks completed by a collection that the ledger no longer covers (after a 6c correction or refund): the
     * affected progress is shown to the authorized person, never erased (INT-07).
     *
     * @return list<string> references
     */
    public static function shortfalls(Order $order): array
    {
        return array_values(array_map(fn (array $c) => $c['reference'], array_filter(self::overview($order),
            fn (array $c) => $c['verified'] && ! $c['met'])));
    }

    public static function threshold(Order $order, OrderAction $action): ?Money
    {
        $inputs = $action->inputs ?? [];
        $value = $inputs['value'] ?? null;

        return match ($inputs['basis'] ?? null) {
            'amount' => is_int($value) ? Money::of($value) : null,
            'percent' => is_int($value) ? OrderAgreement::share($order, $value * 100) : null,
            'remaining' => OrderAgreement::value($order),
            default => null,
        };
    }

    /**
     * @return Collection<int, OrderAction>
     */
    private static function checks(Order $order)
    {
        return OrderAction::query()->where('order_id', $order->id)->where('type', ActionType::PaymentCheck->value)
            ->orderBy('order_stage_id')->orderBy('position')->get();
    }
}
