<?php

namespace App\Enums;

/**
 * What the resolution found. `upheld` tells the person to open the refund or correction from its own action; the
 * dispute itself never moves money.
 */
enum DisputeOutcome: string
{
    case Upheld = 'upheld';
    case PartlyUpheld = 'partly_upheld';
    case Rejected = 'rejected';
}
