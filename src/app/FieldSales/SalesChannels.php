<?php

namespace App\FieldSales;

use App\Enums\SalesChannel;
use App\Models\User;

/**
 * The sales channel of a new staff order (ADR-0041). The store's orders are `online_store` where the store opens
 * them; a staff order is `field_sales` when field sales is on and either a lead dispatched to a rep is being
 * converted (that rep is the seller) or the member opening it is a rep (they are the seller); otherwise `showroom`.
 */
final class SalesChannels
{
    /**
     * @return array{0: SalesChannel, 1: int|null} the channel and the seller
     */
    public static function forNewOrder(User $actor, ?int $dispatchedRepId = null): array
    {
        if (! FieldSalesConfig::enabled()) {
            return [SalesChannel::Showroom, null];
        }
        if ($dispatchedRepId !== null) {
            return [SalesChannel::FieldSales, $dispatchedRepId];
        }

        return FieldReps::isRep($actor) ? [SalesChannel::FieldSales, $actor->id] : [SalesChannel::Showroom, null];
    }
}
