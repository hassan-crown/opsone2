<?php

namespace App\Search\Providers;

use App\Access\Access;
use App\Access\Permission;
use App\Models\Branch;
use App\Models\User;
use App\Organization\OrgPresenter;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;

/**
 * Branches the user may manage (P-03 within the grant's scope).
 */
final class BranchSearchProvider implements SearchProvider
{
    private const CANDIDATES = 100;

    public function key(): string
    {
        return 'branches';
    }

    public function search(User $user, string $term, int $limit): array
    {
        if (! Access::can($user, Permission::P03)) {
            return [];
        }

        $candidates = FulltextMatch::apply(Branch::query(), ['name_ar', 'name_en', 'code'], $term, [['name_ar', 'name_en', 'code']])
            ->orderBy('id')
            ->limit(self::CANDIDATES)
            ->get();

        $results = [];
        foreach ($candidates as $branch) {
            if (! Access::can($user, Permission::P03, $branch)) {
                continue;
            }
            $results[] = new SearchResult('branch', OrgPresenter::name($branch), route('org.branches.index', [], false), $branch->code);
            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }
}
