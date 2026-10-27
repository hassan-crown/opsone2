<?php

namespace App\Notifications;

enum OutboxChannel: string
{
    case Mail = 'mail';
    case Sms = 'sms';
}
