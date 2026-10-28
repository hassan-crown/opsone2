<?php

namespace App\Enums;

/**
 * Ticket type (SHR-08): independent of the order link.
 */
enum TicketType: string
{
    case Issue = 'issue';
    case Question = 'question';
    case Request = 'request';
    case Other = 'other';
}
