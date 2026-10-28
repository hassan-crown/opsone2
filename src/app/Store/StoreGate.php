<?php

namespace App\Store;

use App\Access\Contracts\LicenseCapabilities;
use App\Access\Enums\LicenseMode;
use App\Access\Exceptions\InvalidTransition;
use App\Enums\OrganizationUnitStatus;
use App\Enums\StateMachine;
use App\Models\Branch;
use App\Models\Store\StoreSettings;

/**
 * Is the store selling (WEB-05, SUB-08..10 facility side)? Only while the license allows `ready_goods` in full mode
 * and the facility enabled the store with an active receiving branch. Removing the capability hides the catalogue
 * and refuses every new checkout, link purchase and coupon at once (SAL-17); what already started is still settled
 * from its own source (a verified payment, the expiry service).
 */
final class StoreGate
{
    public function licensed(): bool
    {
        // Resolved per call (scoped per request): a controller instance may outlive the license it first saw.
        $license = app(LicenseCapabilities::class);

        return $license->allows('ready_goods') && $license->mode() === LicenseMode::Full;
    }

    public function open(): bool
    {
        if (! $this->licensed()) {
            return false;
        }
        $settings = StoreSettings::current();

        return $settings->enabled && $settings->branch_id !== null
            && Branch::query()->whereKey($settings->branch_id)->where('status', OrganizationUnitStatus::Active)->exists();
    }

    public function abortUnlessOpen(): void
    {
        abort_unless($this->open(), 404);
    }

    /**
     * Inside a write: the store must still be selling now (a capability removed a second ago refuses).
     */
    public function assertSelling(string $code = 'T02'): StoreSettings
    {
        if (! $this->open()) {
            throw new InvalidTransition(StateMachine::M05, $code, 'store_closed');
        }

        return StoreSettings::current();
    }
}
