<?php

namespace App\Enums;

/**
 * M-05 «طلب الجاهز». P4 implements only the draft (T00); confirmation, payment and delivery come with inventory and
 * the store (P7/P8). The full table is declared so undefined transitions are refused.
 */
enum ReadyOrderStatus: string
{
    case Draft = 'draft';
    case AwaitingPayment = 'awaiting_payment';
    case NeedsAttention = 'needs_attention';
    case Confirmed = 'confirmed';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Closed = 'closed';

    /**
     * Transition code => allowed source states and target (T07/T08/T09 choose among targets, so `to` is null).
     *
     * @return array<string, array{from: list<self>, to: self|null}>
     */
    public static function transitions(): array
    {
        return [
            'T01' => ['from' => [self::Draft], 'to' => self::Confirmed],
            'T02' => ['from' => [self::Draft], 'to' => self::AwaitingPayment],
            'T03' => ['from' => [self::AwaitingPayment], 'to' => self::Confirmed],
            'T04' => ['from' => [self::AwaitingPayment], 'to' => self::Cancelled],
            'T05' => ['from' => [self::AwaitingPayment], 'to' => self::NeedsAttention],
            'T06' => ['from' => [self::Cancelled], 'to' => self::NeedsAttention],
            'T07' => ['from' => [self::NeedsAttention], 'to' => null],
            'T08' => ['from' => [self::Confirmed, self::PartiallyDelivered], 'to' => null],
            'T09' => ['from' => [self::Draft, self::Confirmed, self::PartiallyDelivered], 'to' => null],
            'T10' => ['from' => [self::Delivered, self::Cancelled], 'to' => self::Closed],
        ];
    }

    /**
     * Lines and quantities change only in the draft; a confirmed order changes through its own actions.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this === self::Cancelled || $this === self::Closed;
    }
}
