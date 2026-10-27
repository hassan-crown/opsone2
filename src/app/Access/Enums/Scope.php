<?php

namespace App\Access\Enums;

/**
 * Data scopes from the matrix: حسابي، المسند إليّ، القسم، فروع محددة، منطقة، كامل الجهة.
 */
enum Scope: string
{
    case Own = 'own';
    case Assigned = 'assigned';
    case Department = 'department';
    case Branches = 'branches';
    case Region = 'region';
    case Tenant = 'tenant';

    /**
     * Organizational scopes, whose breadth is chosen per member when a role is assigned.
     * `own` and `assigned` are intrinsic to the action and are never widened by an assignment.
     */
    public function isOrganizational(): bool
    {
        return $this !== self::Own && $this !== self::Assigned;
    }

    /**
     * Scopes that need target ids (department, branch or region ids).
     */
    public function needsTargets(): bool
    {
        return in_array($this, [self::Department, self::Branches, self::Region], true);
    }
}
