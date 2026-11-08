<?php

namespace App\Help;

use App\Models\HelpArticle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Shapes help articles for the pages, the contextual drawer and the home page, always from a HelpReader query.
 */
final class HelpPresenter
{
    /** How many «Start here» articles the checklist shows. */
    public const START_HERE_LIMIT = 6;

    /**
     * @return array{slug: string, title: string, summary: string|null, locked: bool}
     */
    public static function summary(HelpArticle $article, HelpReader $reader): array
    {
        return [
            'slug' => $article->slug,
            'title' => $article->title(),
            'summary' => $article->summary(),
            'locked' => $reader->isLocked($article),
        ];
    }

    /**
     * @return array{slug: string, title: string, summary: string|null, locked: bool, body: string, body_html: string, category: string, updated_at: string|null}
     */
    public static function full(HelpArticle $article, HelpReader $reader): array
    {
        return [
            ...self::summary($article, $reader),
            'body' => $article->body(),
            'body_html' => HelpMarkdown::toHtml($article->body(), $reader->audience),
            'category' => $article->category->value,
            'updated_at' => $article->updated_at?->toIso8601String(),
        ];
    }

    /**
     * «Start here»: the getting-started articles this user may read, until they close the checklist.
     *
     * @return list<array{slug: string, title: string, summary: string|null, locked: bool}>|null
     */
    public static function startHere(User $user): ?array
    {
        if ($user->help_start_dismissed_at !== null) {
            return null;
        }

        $reader = HelpReader::forStaff($user);
        $articles = $reader->query()->where('category', HelpCategory::GettingStarted->value)
            ->orderBy('sort')->orderBy('id')->limit(self::START_HERE_LIMIT)->get();

        return $articles->isEmpty() ? null : array_values($articles->map(fn (HelpArticle $a) => self::summary($a, $reader))->all());
    }

    /**
     * The route name of an in-app path, for the contextual «?». The path is only matched, never followed.
     */
    public static function routeNameFor(string $path): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create('/'.ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/')))->getName();
        } catch (HttpExceptionInterface|UrlGenerationException) {
            return null;
        }
    }

    public static function forPage(HelpReader $reader, ?string $routeName): ?HelpArticle
    {
        return $routeName === null ? null : $reader->query()->where('page_key', $routeName)->orderBy('sort')->orderBy('id')->first();
    }
}
