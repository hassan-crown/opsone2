<?php

namespace App\Operations\Upgrade;

enum MigrationKind: string
{
    case Expand = 'expand';
    case Contract = 'contract';
    // No marker: allowed only for migrations that already ran before ADR-0028 or on a fresh install.
    case Unmarked = 'unmarked';
}
