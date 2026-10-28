<?php

namespace App\Commissions;

use App\Models\Commissions\CommissionEntry;
use App\Models\Commissions\OrderSale;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Keeps a sale's accrual equal to its commission now (SAL-13, INT-06, M-33-T05): one signed difference entry when it
 * changed, nothing otherwise — a repeated event never accrues twice. Called inside the money write's transaction,
 * under the order's lock: by every collection change (`CollectionConditions::evaluate`, so 6c's corrections and
 * refunds follow it too), by the agreement activation and by sale corrections. A negative difference after a payout
 * is carried on the member's statement; nothing paid is erased and no salary is deducted.
 */
final class CommissionAccrual
{
    /**
     * Follows the order's active sale; returns the entry written, if any.
     */
    public function recompute(Order $order, string $source, ?User $actor, ?string $reason = null): ?CommissionEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Commission accrual runs inside the money write.');
        }
        $sale = OrderSale::query()->where('order_id', $order->id)->where('status', OrderSale::ACTIVE)->first();
        if ($sale === null) {
            return null;
        }
        $basis = CommissionBasis::of($order, $sale);
        $accrued = self::accrued($sale);
        $difference = $basis['target_minor'] - $accrued;
        if ($difference === 0) {
            return null;
        }

        return CommissionEntry::query()->create([
            'order_sale_id' => $sale->id,
            'order_id' => $order->id,
            'user_id' => $sale->user_id,
            'kind' => CommissionEntry::ACCRUAL,
            'amount_minor' => $difference,
            'eligible_minor' => $basis['eligible_minor'],
            'collected_minor' => $basis['collected_minor'],
            'agreed_minor' => $basis['agreed_minor'],
            'rate_bp' => $sale->rate_bp,
            'accrued_minor' => $basis['target_minor'],
            'source' => mb_substr($source, 0, 80),
            'reason' => $reason,
            'actor_id' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Moves everything a superseded sale accrued to the sale that replaces it (SAL-16): a `transfer_out` on the old
     * member and a `transfer_in` on the new one, then the new sale is re-derived (a rate correction differs there).
     */
    public function transfer(OrderSale $from, OrderSale $to, string $source, ?User $actor, string $reason): void
    {
        $accrued = self::accrued($from);
        if ($accrued !== 0) {
            foreach ([[$from, CommissionEntry::TRANSFER_OUT, -$accrued, 0], [$to, CommissionEntry::TRANSFER_IN, $accrued, $accrued]] as [$sale, $kind, $amount, $after]) {
                CommissionEntry::query()->create([
                    'order_sale_id' => $sale->id, 'order_id' => $sale->order_id, 'user_id' => $sale->user_id, 'kind' => $kind,
                    'amount_minor' => $amount, 'rate_bp' => $sale->rate_bp, 'accrued_minor' => $after,
                    'source' => mb_substr($source, 0, 80), 'reason' => $reason, 'actor_id' => $actor?->id, 'created_at' => now(),
                ]);
            }
        }
        $this->recompute($to->order, $source, $actor, $reason);
    }

    public static function accrued(OrderSale $sale): int
    {
        return (int) CommissionEntry::query()->where('order_sale_id', $sale->id)->sum('amount_minor');
    }
}
