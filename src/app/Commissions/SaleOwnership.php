<?php

namespace App\Commissions;

use App\Agreements\OrderAgreement;
use App\Enums\OrderKind;
use App\Models\Commissions\CommissionSettings;
use App\Models\Commissions\OrderSale;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pins «صاحب المبيعة» of a made-to-order order when its agreement is first approved (SAL-11, SAL-12): the member
 * following the order (its assignee, else its creator) — never whoever edited the record last — with the rate that
 * applies to them at that moment. Later rate or policy changes never reach a pinned sale (SAL-19); only a sale
 * correction does (SAL-16). Ready orders and basket links arrive with the store (P7).
 */
final class SaleOwnership
{
    /**
     * Under the order's lock. Idempotent: an order keeps its first sale.
     */
    public function pin(Order $order): ?OrderSale
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A sale is pinned inside the agreement transaction.');
        }
        $existing = self::active($order);
        if ($existing !== null || $order->kind !== OrderKind::Custom) {
            return $existing;
        }
        $agreement = OrderAgreement::current($order);
        $ownerId = $order->assignee_id ?? $order->created_by;
        $owner = $ownerId === null ? null : User::query()->find($ownerId);
        if ($agreement === null || $owner === null) {
            return null;
        }
        $rate = CommissionRates::resolve($owner);

        return OrderSale::query()->create([
            'order_id' => $order->id,
            'user_id' => $owner->id,
            'rate_bp' => $rate['rate_bp'],
            'rate_source' => $rate['source'],
            'manual_lines_eligible' => CommissionSettings::current()->manual_lines_eligible,
            'owner_source' => $order->assignee_id !== null ? 'assignee' : 'creator',
            'agreement_id' => $agreement->id,
            'status' => OrderSale::ACTIVE,
            'active_key' => $order->id,
            'pinned_at' => now(),
        ]);
    }

    public static function active(Order $order): ?OrderSale
    {
        return OrderSale::query()->where('order_id', $order->id)->where('status', OrderSale::ACTIVE)->first();
    }
}
