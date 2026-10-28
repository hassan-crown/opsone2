<?php

namespace App\Providers;

use App\Cancellation\CancellationNotices;
use App\Commissions\SettlementHolds;
use App\Finance\Settlement\RefundExecuted;
use App\Models\Cancellation\OrderCancellation;
use App\Notifications\NotificationTargets;
use App\Settlement\CancellationCommissionHolds;
use App\Settlement\SyncSettlementOnRefund;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * P7 cancellation and the settlement follow-up (ADR-0020).
 */
class CancellationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(SettlementHolds::class, fn (SettlementHolds $inner) => new CancellationCommissionHolds($inner));
    }

    public function boot(): void
    {
        Event::listen(RefundExecuted::class, [SyncSettlementOnRefund::class, 'handle']);
        CancellationNotices::define();

        $this->app->make(NotificationTargets::class)->register(
            (new OrderCancellation)->getMorphClass(),
            fn (int $id) => OrderCancellation::query()->with('order')->find($id),
            fn (object $c) => route('orders.cancellation', ['order' => $c instanceof OrderCancellation ? $c->order_id : 0]),
        );
    }
}
