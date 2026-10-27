<?php

namespace App\Search;

use App\Models\User;

/**
 * Runs every registered provider for the signed-in user. Result lists carry no totals: the number of hidden
 * matches is never exposed.
 */
final class SearchService
{
    public const MIN_LENGTH = 2;

    /**
     * @param  iterable<SearchProvider>  $providers
     */
    public function __construct(private readonly iterable $providers) {}

    /**
     * @return list<array{key: string, results: list<SearchResult>}>
     */
    public function search(User $user, string $term, int $limitPerGroup = 5): array
    {
        $term = trim($term);
        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $groups = [];
        foreach ($this->providers as $provider) {
            $results = $provider->search($user, $term, $limitPerGroup);
            if ($results !== []) {
                $groups[] = ['key' => $provider->key(), 'results' => $results];
            }
        }

        return $groups;
    }
}
