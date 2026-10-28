<?php

namespace App\Returns;

use App\Enums\ReturnStatus;
use App\Models\Returns\OrderReturn;
use App\Models\Returns\OrderReturnLine;
use App\Models\Returns\OrderReturnPart;

/**
 * The state of an accepted return derived from its quantities (M-15-T07): nothing received yet → accepted; any
 * received part waiting for inspection, or accepted quantity still expected → received; everything accepted was
 * received and every part inspected → inspected. A return with nothing accepted any more (all released) is cancelled.
 */
final class ReturnProgress
{
    public static function derive(OrderReturn $return): ReturnStatus
    {
        $lines = OrderReturnLine::query()->where('order_return_id', $return->id)->get();
        $parts = OrderReturnPart::query()->where('order_return_id', $return->id)->get();
        $accepted = (int) $lines->sum('accepted_milli');
        if ($accepted === 0) {
            return ReturnStatus::Cancelled;
        }
        if ($parts->isEmpty()) {
            return ReturnStatus::Accepted;
        }
        $outstanding = (int) $lines->sum(fn (OrderReturnLine $l) => $l->outstandingMilli());
        $uninspected = $parts->contains(fn (OrderReturnPart $p) => ! $p->isInspected());

        return $outstanding === 0 && ! $uninspected ? ReturnStatus::Inspected : ReturnStatus::Received;
    }

    /**
     * Value of what the return still holds (accepted quantities), halalas; null when a line has no price.
     */
    public static function value(OrderReturn $return): ?int
    {
        $total = 0;
        foreach (OrderReturnLine::query()->with('orderLine')->where('order_return_id', $return->id)->get() as $line) {
            if ($line->accepted_milli === 0) {
                continue;
            }
            $value = ReturnEligibility::valueOf($line->orderLine, $line->accepted_milli);
            if ($value === null) {
                return null;
            }
            $total += $value;
        }

        return $total;
    }
}
