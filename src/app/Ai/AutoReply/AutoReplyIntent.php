<?php

namespace App\Ai\AutoReply;

/**
 * The five factual questions the automatic reply may answer (plan §2 axis 4, Q-92). Each one has a fixed template in
 * lang/*.json filled only from the database. Anything else is a hand-off to staff.
 */
enum AutoReplyIntent: string
{
    case OrderStatus = 'order_status';
    case Appointment = 'appointment';
    case PaymentDue = 'payment_due';
    case Location = 'location';
    case WorkingHours = 'working_hours';

    /** The classifier's answer for «none of these». */
    public const HANDOFF = 'handoff';

    /** Whether the answer is about the customer's own orders (needs a phone-matched customer). */
    public function personal(): bool
    {
        return in_array($this, [self::OrderStatus, self::Appointment, self::PaymentDue], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $i) => $i->value, self::cases());
    }
}
