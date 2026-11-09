<?php

namespace App\Inbox;

use Carbon\CarbonInterface;

/**
 * Meta's customer-service window: for 24 hours after the contact's last message the business may answer with free
 * text; outside it only an approved template may be sent. Counted from the last inbound message, never from ours.
 */
final class ServiceWindow
{
    public const HOURS = 24;

    public static function isOpen(?CarbonInterface $lastInbound, ?CarbonInterface $at = null): bool
    {
        return $lastInbound !== null && $lastInbound->copy()->addHours(self::HOURS)->isAfter($at ?? now());
    }

    public static function closesAt(?CarbonInterface $lastInbound): ?CarbonInterface
    {
        return $lastInbound?->copy()->addHours(self::HOURS);
    }
}
