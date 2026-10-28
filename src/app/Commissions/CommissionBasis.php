<?php

namespace App\Commissions;

use App\Agreements\OrderAgreement;
use App\Finance\MoneyLedger;
use App\Models\Commissions\OrderSale;
use App\Models\Order;
use App\Money\Rounding;

/**
 * The commission of a sale now (SAL-12, INT-06, scenario 10):
 *
 *   eligible  = commissionable lines of the active agreement after discount and **before VAT** (`net_minor`);
 *               lines typed by hand count only when the pinned policy allows them;
 *   collected = money of the order allocated to its dues — credit without a due (a deposit before the agreement,
 *               an overpayment) earns nothing, a proof under review is not money;
 *   target    = eligible × rate × min(collected, agreed) / agreed, rounded half-up once (Q-34 a).
 *
 * The accrual is cumulative: every event records target − accrued so far, so partial collections sum exactly to
 * the commission of the whole and a replayed event adds nothing.
 */
final class CommissionBasis
{
    /**
     * @return array{eligible_minor: int, collected_minor: int, agreed_minor: int, target_minor: int}
     */
    public static function of(Order $order, OrderSale $sale): array
    {
        $agreement = OrderAgreement::current($order);
        $agreed = $agreement === null ? 0 : (int) $agreement->total_minor;
        $eligible = 0;
        foreach (($agreement?->content['lines'] ?? []) as $line) {
            if (! ($line['commissionable'] ?? false)) {
                continue;
            }
            if (($line['order_line_id'] ?? null) === null && ! $sale->manual_lines_eligible) {
                continue;
            }
            $eligible += (int) ($line['net_minor'] ?? 0);
        }

        $collected = max(0, min($agreed, array_sum(MoneyLedger::allocatedByDue($order->id))));
        $target = $agreed <= 0 || $eligible <= 0 || $sale->rate_bp === 0 || $collected === 0
            ? 0
            : Rounding::divide(
                bcmul(bcmul((string) $eligible, (string) $sale->rate_bp), (string) $collected),
                bcmul('10000', (string) $agreed),
            );

        return ['eligible_minor' => $eligible, 'collected_minor' => $collected, 'agreed_minor' => $agreed, 'target_minor' => $target];
    }
}
