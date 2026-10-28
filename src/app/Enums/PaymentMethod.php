<?php

namespace App\Enums;

/**
 * How the customer paid (FIN-04). `online` is reserved to a verified gateway (FIN-07, P8).
 */
enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Cheque = 'cheque';
    case Online = 'online';

    /**
     * @return list<string>
     */
    public static function manual(): array
    {
        return [self::Transfer->value, self::Cash->value, self::Card->value, self::Cheque->value];
    }

    /**
     * The trusted key of a reference: the same bank transfer or provider transaction is one payment (INT-04). Spaces,
     * dashes and case never make two references different. Null without a reference (cash usually).
     */
    public function referenceKey(?string $reference): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-_.\/]+/u', '', (string) $reference));

        return $normalized === '' ? null : $this->value.':'.$normalized;
    }
}
