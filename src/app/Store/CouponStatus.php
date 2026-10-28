<?php

namespace App\Store;

/**
 * SAL-06: a coupon's state derived from its dates and pause flag (scheduled, active, paused, ended).
 */
enum CouponStatus: string
{
    case Scheduled = 'scheduled';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
