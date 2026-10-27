<?php

namespace App\Actions\StateMachines\M02Setup;

use App\Models\Facility;

/**
 * The five steps of the setup wizard (ORG-08) and what each one has recorded: `done`, `skipped` or untouched.
 * «Resume» opens the first step that is not done; skipping never marks a step as done.
 */
final class SetupSteps
{
    public const ORG = 'org';

    public const STRUCTURE = 'structure';

    public const FLOW = 'flow';

    public const TEAM = 'team';

    public const REVIEW = 'review';

    /** @var list<string> */
    public const ALL = [self::ORG, self::STRUCTURE, self::FLOW, self::TEAM, self::REVIEW];

    /** Steps the wizard cannot be completed without. */
    public const REQUIRED = [self::ORG];

    /**
     * @return array<string, 'done'|'skipped'|null>
     */
    public static function states(Facility $facility): array
    {
        $saved = $facility->setup_steps ?? [];
        $states = [];
        foreach (self::ALL as $step) {
            $value = $saved[$step] ?? null;
            $states[$step] = $value === 'done' || $value === 'skipped' ? $value : null;
        }

        return $states;
    }

    public static function next(Facility $facility): string
    {
        foreach (self::states($facility) as $step => $state) {
            if ($step !== self::REVIEW && $state !== 'done') {
                return $step;
            }
        }

        return self::REVIEW;
    }

    public static function doneCount(Facility $facility): int
    {
        return count(array_filter(self::states($facility), fn ($state) => $state === 'done'));
    }
}
