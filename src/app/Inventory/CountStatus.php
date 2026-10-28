<?php

namespace App\Inventory;

/**
 * A stock count: drafted and submitted by whoever counts, approved (posting the adjustments) or rejected by a P-49
 * holder covering the location.
 */
enum CountStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
