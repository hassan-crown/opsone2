<?php

namespace App\Enums;

/**
 * M-08 «المستند المطلوب اعتماده».
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case AwaitingCustomer = 'awaiting_customer';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Expired = 'expired';
    case Replaced = 'replaced';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::AwaitingCustomer],
            'T02' => ['from' => [self::AwaitingCustomer], 'to' => self::Approved],
            'T03' => ['from' => [self::AwaitingCustomer], 'to' => self::ChangesRequested],
            'T04' => ['from' => [self::AwaitingCustomer, self::ChangesRequested, self::Expired], 'to' => self::Replaced],
            'T05' => ['from' => [self::AwaitingCustomer], 'to' => self::Expired],
            'T06' => ['from' => [self::Expired], 'to' => self::AwaitingCustomer],
            'T07' => ['from' => [self::Approved], 'to' => self::Replaced],
            'T08' => ['from' => [self::Draft, self::AwaitingCustomer, self::ChangesRequested], 'to' => self::Cancelled],
        ];
    }

    /** A document still in play: a newer one replaces it instead of standing beside it. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::AwaitingCustomer, self::ChangesRequested, self::Expired], true);
    }
}
