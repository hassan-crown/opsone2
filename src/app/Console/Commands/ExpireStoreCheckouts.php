<?php

namespace App\Console\Commands;

use App\Store\StoreCheckouts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Time service for M-05-T04 (WEB-06): ended reservation windows are settled from the provider's answer, never assumed.
 */
#[Signature('store:expire-checkouts')]
#[Description('Settle store checkouts whose reservation window ended (re-reads Moyasar first)')]
final class ExpireStoreCheckouts extends Command
{
    public function handle(StoreCheckouts $checkouts): int
    {
        $this->info(sprintf('Settled %d checkout(s).', $checkouts->expireDue()));

        return self::SUCCESS;
    }
}
