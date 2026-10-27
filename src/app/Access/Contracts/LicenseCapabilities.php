<?php

namespace App\Access\Contracts;

use App\Access\Enums\LicenseMode;

/**
 * First step of the Gate: does the installed license enable this capability, and what does its phase leave open?
 * Bound to App\Access\LicensedCapabilities (over App\Licensing\CurrentLicense).
 */
interface LicenseCapabilities
{
    public function allows(string $capability): bool;

    public function mode(): LicenseMode;
}
