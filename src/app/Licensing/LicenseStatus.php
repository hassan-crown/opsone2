<?php

namespace App\Licensing;

/**
 * License states from M-19 (ADR-0004). "expired" is the read-and-export phase.
 */
enum LicenseStatus: string
{
    case Provisioned = 'provisioned';
    case Active = 'active';
    case Grace = 'grace';
    case WindDown = 'wind_down';
    case Expired = 'expired';
    case NeedsAttention = 'needs_attention';

    /**
     * Whether the license's capabilities may be used (wind-down only to finish existing work).
     */
    public function grantsCapabilities(): bool
    {
        return in_array($this, [self::Active, self::Grace, self::WindDown], true);
    }

    /**
     * Whether new orders, users and other new work may start.
     */
    public function acceptsNewWork(): bool
    {
        return in_array($this, [self::Active, self::Grace], true);
    }
}
