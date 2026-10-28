<?php

namespace App\Providers;

use App\Commissions\SettlementHolds;
use App\Finance\DisputedAmounts;
use App\Finance\Settlement\OpenDisputeAmounts;
use App\Finance\Settlement\SettlementCommissionHolds;
use Illuminate\Support\ServiceProvider;

/**
 * P6c settlement (ADR-0015): open financial disputes hold their part of a due from collection reminders; pending
 * refunds and open disputes hold the order's commission from payouts (decorates 6d's SettlementHolds).
 */
class SettlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DisputedAmounts::class, OpenDisputeAmounts::class);
        $this->app->bind(SettlementHolds::class, SettlementCommissionHolds::class);
    }
}
