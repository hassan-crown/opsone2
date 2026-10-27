<?php

namespace App\Search;

use App\Models\User;

/**
 * A source of global-search results. Later domains (customers, orders, tasks) add a provider and tag it
 * `search.providers` in a service provider. Contract: return ONLY records the user may open — decide with
 * `Access::can()` per record, never with ids or counts that reveal what is hidden (SHR-09).
 */
interface SearchProvider
{
    /**
     * Stable group key, used for ordering and the group heading (`search.group.{key}`).
     */
    public function key(): string;

    /**
     * @return list<SearchResult>
     */
    public function search(User $user, string $term, int $limit): array;
}
