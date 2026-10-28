<?php

namespace App\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\ChangeStatus;
use App\Models\Agreements\Agreement;
use App\Models\Agreements\AgreementChange;
use App\Models\Order;
use App\Money\Money;
use Illuminate\Support\Collection;

/**
 * Read API of the order's agreement for P6b (dues, collections, INT-07 payment conditions) and P6d (commission
 * base). No authorization here: callers check P-37 before showing any amount. See docs/p6-finance-api.md.
 */
final class OrderAgreement
{
    public static function current(Order $order): ?Agreement
    {
        return Agreement::query()->where('order_id', $order->id)->where('status', AgreementStatus::Active->value)->first();
    }

    /**
     * The agreed value (VAT-inclusive total), or null before the first approval.
     */
    public static function value(Order $order): ?Money
    {
        $agreement = self::current($order);

        return $agreement === null ? null : Money::of($agreement->total_minor);
    }

    /**
     * Value of commission-eligible product lines of the active version (SAL-10, INT-06).
     */
    public static function productsValue(Order $order): ?Money
    {
        $agreement = self::current($order);

        return $agreement === null ? null : Money::of($agreement->products_minor);
    }

    /**
     * A share of the agreed value in basis points, half-up — what a `payment_check` with basis `percent` compares
     * the cumulative collected amount against (INT-07: 50% then 100% of the same agreement).
     */
    public static function share(Order $order, int $basisPoints): ?Money
    {
        return self::value($order)?->percent($basisPoints);
    }

    /**
     * Every version, oldest first (superseded ones are immutable proof).
     *
     * @return Collection<int, Agreement>
     */
    public static function history(Order $order): Collection
    {
        return Agreement::query()->where('order_id', $order->id)->orderBy('version')->get();
    }

    /**
     * The open change, if any: work it lists waits; the active agreement stays in force (AGR-07).
     */
    public static function pendingChange(Order $order): ?AgreementChange
    {
        return AgreementChange::query()->where('order_id', $order->id)
            ->whereIn('status', [ChangeStatus::Draft->value, ChangeStatus::AwaitingApproval->value, ChangeStatus::NeedsRevision->value])
            ->first();
    }
}
