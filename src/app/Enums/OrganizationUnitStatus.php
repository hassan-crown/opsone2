<?php

namespace App\Enums;

/**
 * Branch, region and department status (disabled units keep their history).
 */
enum OrganizationUnitStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
