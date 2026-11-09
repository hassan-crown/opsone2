<?php

namespace App\Ai\Inbox;

/**
 * The closed list an inbound message is classified into (plan §2 axis 2). The model can only pick one of these; it
 * never decides anything else. AI-6 maps some of them to approved templates.
 */
enum InboxIntent: string
{
    case OrderStatus = 'order_status';
    case Appointment = 'appointment';
    case Payment = 'payment';
    case NewCustomer = 'new_customer';
    case Complaint = 'complaint';
    case MeasurementPhotos = 'measurement_photos';
    case Greeting = 'greeting';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $i) => $i->value, self::cases());
    }
}
