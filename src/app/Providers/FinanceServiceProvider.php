<?php

namespace App\Providers;

use App\Agreements\AgreementApplied;
use App\Agreements\CollectedAmounts;
use App\Finance\CollectionNotices;
use App\Finance\DisputedAmounts;
use App\Finance\Gateway\GatewayRegistry;
use App\Finance\LedgerCollectedAmounts;
use App\Finance\NoDisputes;
use App\Finance\SyncScheduleOnAgreement;
use App\Models\Order;
use App\Notifications\NotificationTargets;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * P6b collections (ADR-0014): binds the ledger as 6a's CollectedAmounts, follows agreement activations into the
 * schedule, registers finance notifications (their link opens the order's finance page after the Gate re-checks
 * P-37) and the (empty in v1) online gateway registry.
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CollectedAmounts::class, LedgerCollectedAmounts::class);
        $this->app->bindIf(DisputedAmounts::class, NoDisputes::class);
        $this->app->singleton(GatewayRegistry::class);
    }

    public function boot(): void
    {
        Event::listen(AgreementApplied::class, [SyncScheduleOnAgreement::class, 'handle']);
        CollectionNotices::define();

        $this->app->make(NotificationTargets::class)->register(
            (new Order)->getMorphClass(),
            fn (int $id) => Order::query()->find($id),
            fn (object $order) => route('orders.finance', ['order' => $order instanceof Order ? $order->id : 0]),
        );
    }
}
