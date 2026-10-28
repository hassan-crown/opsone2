<?php

namespace App\Access\Contracts;

use App\Access\Permission;

/**
 * A record whose licensed part depends on the record itself, not on the permission alone: P-15 covers both a custom
 * order (every plan) and a ready order (`ready_goods`), so the Gate asks the record (ADR-0005 note 8, ADR-0011).
 */
interface LicensedRecord
{
    /**
     * License capability this action needs on this record, or null when the permission's own check is enough.
     */
    public function requiredCapability(Permission $permission): ?string;
}
