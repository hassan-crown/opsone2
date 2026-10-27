<?php

namespace App\Access;

use App\Access\Contracts\LicenseCapabilities;

/**
 * TODO(P2 wave 2): replace with an adapter over App\Licensing's verified license (capabilities `ready_goods`,
 * `comparisons`, ...) and deny everything when the license is missing, tampered or expired (M-19-T05).
 * Placeholder only: allows every capability.
 */
final class PermissiveLicenseCapabilities implements LicenseCapabilities
{
    public function allows(string $capability): bool
    {
        return true;
    }
}
