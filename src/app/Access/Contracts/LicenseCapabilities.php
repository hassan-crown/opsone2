<?php

namespace App\Access\Contracts;

/**
 * First step of the Gate: does the installed license enable this capability?
 * Implemented on top of App\Licensing in wave 2; until then PermissiveLicenseCapabilities is bound.
 */
interface LicenseCapabilities
{
    public function allows(string $capability): bool;
}
