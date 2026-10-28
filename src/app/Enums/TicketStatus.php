<?php

namespace App\Enums;

/**
 * M-21 «تذكرة العميل». T01 take (P-59), T02 request information (P-59), T03 customer reply (P-71), T04 resolve (P-59),
 * T05 close (P-59 or the customer, P-71), T06 reopen (P-59 or the customer's reply, P-71). Creation is the implicit T00.
 */
enum TicketStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case AwaitingCustomer = 'awaiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * @return array<string, array{from: list<self>, to: self}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::New], 'to' => self::InProgress],
            'T02' => ['from' => [self::InProgress], 'to' => self::AwaitingCustomer],
            'T03' => ['from' => [self::AwaitingCustomer], 'to' => self::InProgress],
            'T04' => ['from' => [self::InProgress], 'to' => self::Resolved],
            'T05' => ['from' => [self::Resolved], 'to' => self::Closed],
            'T06' => ['from' => [self::Resolved, self::Closed], 'to' => self::InProgress],
        ];
    }

    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }
}
