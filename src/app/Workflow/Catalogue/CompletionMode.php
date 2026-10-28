<?php

namespace App\Workflow\Catalogue;

/**
 * How an action's result is verified (M-27-T03). Never configurable by the merchant (D-03).
 *
 * - `task`: completing its task (with any attachment the inputs require) is the result.
 * - `measurement`: completing its task needs saved measurements of the required kind (COM-07).
 * - `source`: only the owning record verifies it — document approval (P6), confirmed collection (P6), accepted
 *   receipt (P7), recorded delivery (P7) — through App\Workflow\ActionResults::verifyFromSource(). A task never does.
 */
enum CompletionMode: string
{
    case Task = 'task';
    case Measurement = 'measurement';
    case Source = 'source';

    public function createsTask(): bool
    {
        return $this !== self::Source;
    }
}
