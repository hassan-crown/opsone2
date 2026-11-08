<?php

namespace App\Search\Providers;

use App\Help\HelpReader;
use App\Models\HelpArticle;
use App\Models\User;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;
use Illuminate\Support\Str;

/**
 * Published staff help articles the user may read (ADR-0048): the same HelpReader filter as the help pages, applied
 * in the query, so an article the user lacks the permission (or plan) for is never found.
 */
final class HelpArticleSearchProvider implements SearchProvider
{
    public function key(): string
    {
        return 'help';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $articles = HelpReader::forStaff($user)->query();
        FulltextMatch::apply($articles, ['title_ar', 'summary_ar', 'body_ar', 'title_en', 'summary_en', 'body_en'], $term, [['title_ar', 'summary_ar', 'body_ar'], ['title_en', 'summary_en', 'body_en']]);

        return array_values($articles->orderBy('sort')->limit($limit)->get()
            ->map(fn (HelpArticle $a) => new SearchResult(
                'help',
                $a->title(),
                route('help.article', $a, false),
                $a->summary() === null ? null : Str::limit($a->summary(), 90),
            ))
            ->all());
    }
}
