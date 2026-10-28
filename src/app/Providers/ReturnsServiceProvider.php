<?php

namespace App\Providers;

use App\Commissions\SettlementHolds;
use App\Models\Returns\OrderReturn;
use App\Notifications\NotificationTargets;
use App\Returns\Contracts\ReturnRefunds;
use App\Returns\Contracts\SettlementFollowUp;
use App\Returns\M12ReturnRefunds;
use App\Returns\ReturnSettlementHolds;
use App\Settlement\ReturnSettlementFollowUp;
use Illuminate\Support\ServiceProvider;

/**
 * P7 returns (ADR-0019): refunds through P6c's M-12 request, the order's one settlement follow-up (`return` items,
 * App\Settlement), open returns holding the order's commission from payouts (decorates the
 * SettlementHolds bound by 6c) and notification links to the return page.
 */
class ReturnsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReturnRefunds::class, M12ReturnRefunds::class);
        $this->app->bind(SettlementFollowUp::class, ReturnSettlementFollowUp::class);
        $this->app->extend(SettlementHolds::class, fn (SettlementHolds $inner) => new ReturnSettlementHolds($inner));
    }

    public function boot(): void
    {
        $this->app->make(NotificationTargets::class)->register(
            (new OrderReturn)->getMorphClass(),
            fn (int $id) => OrderReturn::query()->with('order')->find($id),
            fn (object $return) => route('returns.show', ['orderReturn' => $return instanceof OrderReturn ? $return->id : 0]),
        );
    }
}
