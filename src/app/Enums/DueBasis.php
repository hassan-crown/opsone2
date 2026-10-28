<?php

namespace App\Enums;

/**
 * How a due's amount is fixed (FIN-02): a fixed amount, a share of the approved agreement, or what the other dues
 * leave (the last due takes the rounding difference).
 */
enum DueBasis: string
{
    case Amount = 'amount';
    case Percent = 'percent';
    case Rest = 'rest';
}
