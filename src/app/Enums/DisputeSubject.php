<?php

namespace App\Enums;

enum DisputeSubject: string
{
    case Duplicate = 'duplicate';
    case Amount = 'amount';
    case Settlement = 'settlement';
    case Refund = 'refund';
    case Other = 'other';
}
