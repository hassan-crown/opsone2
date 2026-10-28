<?php

namespace App\Operations\Upgrade;

use Attribute;

/**
 * Marks a migration as CONTRACT (NFR-15, ADR-0028): it drops, renames or tightens something the previous release may
 * still use. It ships at least one release after the code stopped using it and runs only in maintenance mode, after a
 * fresh backup; rolling the code back past it needs a restore.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Contract {}
