<?php

namespace App\Search\Providers;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Membership;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;

/**
 * Team members the user may open on the team screens (P-04/P-05/P-06 within the grant's scope).
 */
final class MemberSearchProvider implements SearchProvider
{
    private const CANDIDATES = 100;

    public function key(): string
    {
        return 'members';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (! $this->mayList($user)) {
            return [];
        }

        $candidates = Membership::query()
            ->with(['user', 'department'])
            ->whereIn('user_id', FulltextMatch::apply(User::query(), ['name', 'email'], $term, [['name', 'email']])->select('id'))
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        $results = [];
        foreach ($candidates as $membership) {
            if (! $this->mayOpen($user, $membership)) {
                continue;
            }
            $results[] = new SearchResult(
                'member',
                $membership->user->name,
                route('org.team.member', $membership, false),
                $membership->job_title ?? ($membership->department === null ? null : OrgPresenter::name($membership->department)),
            );
            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }

    private function mayList(User $user): bool
    {
        return Access::can($user, Permission::P04) || Access::can($user, Permission::P05) || Access::can($user, Permission::P06);
    }

    private function mayOpen(User $user, Membership $membership): bool
    {
        // Same rule as MemberController::show — any of the three, evaluated against this very record.
        return Access::can($user, Permission::P04, $membership)
            || Access::can($user, Permission::P05, $membership)
            || Access::can($user, Permission::P06, $membership);
    }
}
