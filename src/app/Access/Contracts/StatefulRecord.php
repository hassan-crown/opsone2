<?php

namespace App\Access\Contracts;

use App\Access\Permission;

/**
 * Last step of the Gate: does the record's current state allow the action at all?
 */
interface StatefulRecord
{
    public function permitsAction(Permission $permission): bool;
}
