<?php

namespace App\Providers;

use App\Inventory\Contracts\StockReceiver;
use App\Inventory\StockLedger;
use Illuminate\Support\ServiceProvider;

/**
 * P7a inventory (ADR-0017): the stock ledger is the single write path and serves procurement's StockReceiver seam.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockReceiver::class, StockLedger::class);
    }
}
