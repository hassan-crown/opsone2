<?php

namespace App\Home;

use App\Access\Permission;

/**
 * Descriptor of one work-center widget (SHR-01). Domains fill a widget with data later; until then the
 * frame renders a placeholder. `permission` gates visibility for the signed-in user (Gate, tenant-wide check).
 */
final readonly class HomeWidget
{
    public const SECTION_TASKS = 'tasks';

    public const SECTION_ALERTS = 'alerts';

    public function __construct(
        public string $key,
        public string $section,
        public Permission $permission,
    ) {}
}
