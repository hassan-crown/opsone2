<?php

namespace App\Search\Providers;

use App\Models\HelpArticle;
use App\Models\User;
use App\Search\FulltextMatch;
use App\Search\SearchProvider;
use App\Search\SearchResult;
use Illuminate\Support\Str;

/**
 * Published help articles: readable by every signed-in user (P-83), so no per-record scope applies.
 */
final class HelpArticleSearchProvider implements SearchProvider
{
    public function key(): string
    {
        return 'help';
    }

    public function search(User $user, string $term, int $limit): array
    {
        $articles = HelpArticle::query()->published();
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
