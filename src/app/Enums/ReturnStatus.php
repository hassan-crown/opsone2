<?php

namespace App\Enums;

/**
 * M-15 «مرتجع جاهز»: requested → accepted (T05) | rejected (T06); accepted → received for inspection (T01, T07 for
 * further parts) → inspected (T02, T07); withdrawal (T03) before any receipt cancels, after a partial receipt releases
 * only the unreceived remainder; an inspected return keeps its result and is corrected by T04 with a counter movement.
 * After a receipt the state is derived from the quantities (T07).
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Received = 'received';
    case Inspected = 'inspected';
    case Cancelled = 'cancelled';

    /** Accepted quantities in these states hold return eligibility (INT-03). */
    public function holdsEligibility(): bool
    {
        return in_array($this, [self::Accepted, self::Received, self::Inspected], true);
    }

    /** The commercial decision is still open, or goods are still expected or under inspection. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Accepted, self::Received], true);
    }

    /**
     * @return array<string, array{from: list<self>, to: list<self>}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Accepted], 'to' => [self::Received, self::Inspected]],
            'T02' => ['from' => [self::Received], 'to' => [self::Received, self::Inspected, self::Accepted]],
            'T03' => ['from' => [self::Requested, self::Accepted, self::Received], 'to' => [self::Cancelled, self::Received, self::Inspected]],
            'T04' => ['from' => [self::Inspected, self::Received, self::Accepted], 'to' => [self::Inspected, self::Received, self::Accepted]],
            'T05' => ['from' => [self::Requested], 'to' => [self::Accepted]],
            'T06' => ['from' => [self::Requested], 'to' => [self::Rejected]],
            'T07' => ['from' => [self::Accepted, self::Received], 'to' => [self::Received, self::Inspected]],
        ];
    }
}
