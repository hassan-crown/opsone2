<?php

namespace App\Providers;

use App\Agreements\AgreementApplied;
use App\Commissions\LedgerSettlementHolds;
use App\Commissions\PinSaleOnAgreement;
use App\Commissions\SettlementHolds;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * P6d sale ownership and commissions (ADR-0016): pins the sale on the first agreement and follows every agreement
 * activation; collections re-derive the accrual through CollectionConditions. 6c decorates SettlementHolds with
 * its pending refunds and open disputes.
 */
class CommissionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(SettlementHolds::class, LedgerSettlementHolds::class);
    }

    public function boot(): void
    {
        Event::listen(AgreementApplied::class, [PinSaleOnAgreement::class, 'handle']);
    }
}
