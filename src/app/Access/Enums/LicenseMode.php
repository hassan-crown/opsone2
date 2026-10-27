<?php

namespace App\Access\Enums;

/**
 * What the license phase (M-19) leaves open to staff, independent of capabilities (ADR-0005).
 */
enum LicenseMode: string
{
    // active, grace: everything the plan includes.
    case Full = 'full';

    // wind_down: finish existing work; no new orders or users (M-19-T02).
    case NoNewWork = 'no_new_work';

    // expired, provisioned, or needs_attention without a trusted license: read and export only.
    case ReadOnly = 'read_only';
}
