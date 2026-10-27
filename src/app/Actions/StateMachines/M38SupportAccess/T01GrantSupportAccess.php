<?php

namespace App\Actions\StateMachines\M38SupportAccess;

use App\Access\Access;
use App\Access\Authorizer;
use App\Access\Enums\Scope;
use App\Access\Permission;
use App\Access\TransitionRecorder;
use App\Enums\SupportGrantStatus;
use App\Models\SupportGrant;
use App\Models\User;
use App\SupportAccess\SupportArea;

/**
 * M-38-T01: the owner (P-79) opens a requested grant — never wider or longer than requested, only areas the
 * decider can see facility-wide, and never past the facility maximum. The window starts now.
 */
final class T01GrantSupportAccess extends SupportAccessTransition
{
    /**
     * @param  list<string>  $areas
     */
    public function handle(User $actor, SupportGrant $grant, array $areas, int $hours, ?string $reason = null, string $source = TransitionRecorder::SOURCE_WEB): SupportGrant
    {
        $areas = array_values(array_unique($areas));

        return $this->move('T01', $grant, [SupportGrantStatus::Requested], SupportGrantStatus::Active, $actor,
            function (SupportGrant $g) use ($actor, $areas, $hours): ?string {
                if (! Access::can($actor, Permission::P79)) {
                    return 'not_authorized';
                }
                if ($g->request_expires_at->isPast()) {
                    return 'request_lapsed';
                }
                if ($areas === [] || array_diff($areas, $g->requested_areas) !== []) {
                    return 'areas_not_requested';
                }
                if ($hours < 1 || $hours > $g->requested_hours || $hours > (int) config('opsone.support.max_access_hours', 72)) {
                    return 'hours_out_of_range';
                }
                // Delegation boundary: a decider cannot open more than they hold facility-wide themselves.
                foreach ($areas as $area) {
                    foreach (SupportArea::from($area)->permissions() as $permission) {
                        if (! self::holdsFacilityWide($actor, $permission)) {
                            return 'area_beyond_decider';
                        }
                    }
                }

                return null;
            },
            fn (): array => [
                'areas' => $areas,
                'hours' => $hours,
                'starts_at' => now(),
                'expires_at' => now()->addHours($hours),
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
            ],
            $source, $reason,
        );
    }

    private static function holdsFacilityWide(User $actor, Permission $permission): bool
    {
        foreach (app(Authorizer::class)->grantsFor($actor, $permission) as $grant) {
            if ($grant->scope === Scope::Tenant) {
                return true;
            }
        }

        return false;
    }
}
