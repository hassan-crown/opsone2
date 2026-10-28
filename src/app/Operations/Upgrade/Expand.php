<?php

namespace App\Operations\Upgrade;

use Attribute;

/**
 * Marks a migration as EXPAND (NFR-15, ADR-0028): it only adds (tables, nullable or defaulted columns, indexes) so the
 * previous release keeps working on the new schema. Expand migrations run before the new code, without downtime.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Expand {}
