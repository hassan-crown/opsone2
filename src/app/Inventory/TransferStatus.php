<?php

namespace App\Inventory;

/**
 * M-25 «نقل المخزون».
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
