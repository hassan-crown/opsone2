<?php

namespace App\Providers;

use App\Finance\DisputedAmounts;
use App\Finance\Settlement\OpenDisputeAmounts;
use Illuminate\Support\ServiceProvider;

/**
 * P6c settlement (ADR-0015): open financial disputes hold their part of a due from collection reminders.
 */
class SettlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DisputedAmounts::class, OpenDisputeAmounts::class);
    }
}
