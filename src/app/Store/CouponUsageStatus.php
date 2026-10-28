<?php

namespace App\Store;

/**
 * M-32 «استخدام كوبون الجاهز».
 */
enum CouponUsageStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
